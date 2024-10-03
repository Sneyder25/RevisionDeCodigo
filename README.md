# RevisionDeCodigo
Repositorio donde se hace una revisión de Código con SonarLint

## Primera Violación (Cognitive Complexity of functions should not be too high)
```php
public function grabar()
    {
        try {
            $db = Yii::$app->db;
            $tr = $db->beginTransaction();
            if (!is_null($tr)) {
                // Cargar la imagen
                $imagen = UploadedFile::getInstance($this, 'ordenserv');
                if (empty($imagen)) {
                    return false;
                }
                $extension = pathinfo($imagen->name, PATHINFO_EXTENSION);
                if (strtolower($extension) !== 'pdf') {
                    Yii::$app->session->setFlash('error', 'El archivo debe ser de tipo PDF.');
                    return false;
                }
                //
                $pagos = CursoPago::find()->where(['id' => $this->id_pagos])->one();
                $grupo = Grupos::find()->where(['cod' => $this->grupo_cod])->one();
                $archivo = UploadedFile::getInstance($this, 'archivo');
                $tmp = $archivo->baseName . '.' . $archivo->extension;
                $pth = 'excel/';
                if (!is_dir($pth)) {
                    if (!mkdir($pth, 0777, true)) {
                        $tr->rollBack();
                        return false;
                    }
                }
                if ($archivo->saveAs($pth . $tmp)) {
                    if (in_array($archivo->extension, array('xls', 'xlsx'))) {
                        $data = Excel::widget([
                            'mode' => 'import',
                            'fileName' => $pth . $tmp,
                            'setFirstRecordAsKeys' => true,
                            'setIndexSheetByName' => true,
                            'getOnlySheet' => trim($grupo->cod),
                        ]);
                        $errores = array();
                        $cell = 2;
                        $errores = [];
                        $datosValidos = false; // Para verificar si hay al menos una fila válida

                        foreach ($data as $fila) {
                            if (empty($fila["DOCUMENTO"]) || empty($fila["NOMBRE"]) || empty($fila["PATERNO"]) || empty($fila["MATERNO"]) || empty($fila["SEXO"])) {
                                if (!empty($fila["DOCUMENTO"]) || !empty($fila["NOMBRE"]) || !empty($fila["PATERNO"]) || !empty($fila["MATERNO"]) || !empty($fila["SEXO"])) {
                                    $fila['OBS'] = 'Fila incompleta.';
                                    $errores[] = $fila;
                                }
                                continue;
                            }

                            $datosValidos = true;
                            $fila['FILA'] = 'A' . ($cell++);
                            $fila['OBS'] = '';
                            $alumno = Alumnos::find()->where(['num_documento' => $fila["DOCUMENTO"]])->one();
                            if (is_null($alumno)) {
                                $alumno = new Alumnos();
                                $alumno->nombres = $fila["NOMBRE"];
                                $alumno->apellidos = $fila["PATERNO"] . ' ' . $fila["MATERNO"];
                                $alumno->tipo_documento = $fila["TIPODOCUMENTO"];
                                $alumno->num_documento = $fila["DOCUMENTO"];
                                $alumno->telefono = $fila["TELEFONO"];
                                $alumno->correo = $fila["CORREO"];
                                $alumno->correo2 = $fila["CORREO2"];
                                $alumno->sexo = $fila["SEXO"];
                                if (!$alumno->save()) {
                                    foreach ($alumno->getErrors() as $err) {
                                        $fila['OBS'] = $fila['OBS'] . ' ' . implode(', ', $err);
                                    }
                                    $errores[] = $fila;
                                    $alumno = null;
                                }
                            }
                            if (!is_null($alumno)) {
                                $pre = new Prematricula();
                                $pre->grupo_cod = $grupo->cod;
                                $pre->pre_fdescuento = $fila["DESCUENTO"];
                                if ($fila["DESCUENTO"] == 0){
                                    $pre->pretipinsc_icodigo = 1;
                                }
                                elseif ($fila["DESCUENTO"] == 50){
                                    $pre->pretipinsc_icodigo = 2;
                                }
                                elseif ($fila["DESCUENTO"] == 100){
                                    $pre->pretipinsc_icodigo = 3;
                                }
                                $pre->monto = $pagos->curcos_fmonto * (100 - $pre->pre_fdescuento) / 100;
                                $pre->id_pago = $this->id_pagos;
                                $pre->id_alumno = $alumno->id;
                                $pre->banco = null;
                                if ($pre->save()) {
                                    $this->detallePago = new DetallePago();
                                    if (!empty($this->nro_operacion)){
                                        $this->detallePago->nro_operacion = $this->nro_operacion;

                                    }
                                    else{
                                        $this->nro_operacion = 'vacio';
                                        $this->detallePago->nro_operacion = $this->nro_operacion;
                                    }
                                    $this->detallePago->fch_pago = $this->fch_pago;
                                    $this->detallePago->monto = $pagos->curcos_fmonto * (100 - $pre->pre_fdescuento) / 100;
                                    $this->detallePago->banco_receptor = null;
                                    $this->detallePago->banco_alumno = null;
                                    $this->detallePago->monto_total = $this->monto_total;
                                    $this->detallePago->orden_servicio = true;
                                    $this->detallePago->voucher = '0000.pdf';
                                    if ($this->detallePago->save()) {
                                        $this->detallePago->voucher = $pre->id . '.pdf';
                                        if ($this->detallePago->save()) {
                                            if ($this->voucherUpload($imagen)) {
                                                $matri = new Matriculas();
                                                $matri->fch_matricula = date('Y-m-d');
                                                $matri->id_detalle_pago = $this->detallePago->id_detalle_pago;
                                                $matri->id_prematricula = $pre->id;
                                                $matri->modalidad = 'EXT';
                                                $matri->id_grupo = $grupo->id_grupo;
                                                $matri->id_alumno = $alumno->id;
                                                $matri->imagen = $this->imagen;
                                                if($matri->save()){
                                                    $pre->estado = false;
                                                }
                                                if (!$pre->save())
                                                {
                                                    foreach ($matri->getErrors() as $err) {
                                                        $fila['OBS'] = $fila['OBS'] . ' ' . implode(', ', $err);
                                                    }
                                                    $errores[] = $fila;
                                                }
                                            } else {
                                                Yii::$app->session->setFlash('warning', 'No se cargo ningun voucher.');
                                            }
                                        } else {
                                            $fila = 'No se pudo actualizar voucher en el pago.';
                                            foreach ($this->detallePago->getErrors() as $err) {
                                                $fila = $fila . ' ' . implode(', ', $err);
                                            }
                                            Yii::$app->session->setFlash('error', $fila);
                                        }
                                    } else {
                                        $fila = 'No se pudo guardar el pago. ';
                                        foreach ($this->detallePago->getErrors() as $err) {
                                            $fila = $fila . ' ' . implode(', ', $err);
                                        }
                                        Yii::$app->session->setFlash('error', $fila);
                                    }

                                } else {
                                    foreach ($pre->getErrors() as $err) {
                                        $fila['OBS'] = $fila['OBS'] . ' ' . implode(', ', $err);
                                    }
                                    $errores[] = $fila;
                                }
                            }
                        }
                        if ($datosValidos) {
                            if (!empty($errores)) {
                                $provider = new ArrayDataProvider([
                                    'allModels' => $errores,
                                    'pagination' => [
                                        'pageSize' => 1000,
                                    ],
                                    'sort' => [
                                        'attributes' => ['num_documento'],
                                    ]
                                ]);
                                $tr->rollBack();
                                return $provider;
                            } else {
                                $tr->commit();
                                Yii::$app->session->setFlash('success', 'Grabación exitosa.');
                                return null;
                            }
                        } else {
                            // Si no hay datos válidos, haz rollback y muestra un mensaje adecuado
                            $tr->rollBack();
                            Yii::$app->session->setFlash('error', 'No se encontraron datos válidos para guardar.');
                            return null;
                        }
                    } else {
                        Yii::$app->session->setFlash('error', 'Extensión del excel incorrecta.');
                    }
                } else {
                    Yii::$app->session->setFlash('error', 'No se pudo guardar el excel.');
                }
                $tr->rollBack();
            } else {
                Yii::$app->session->setFlash('error', 'No se pudo iniciar transaccion.');
            }
        } catch (\Exception $ex) {
            if (!is_null($tr)) {
                $tr->rollBack();
            }
            Yii::$app->session->setFlash('error', 'Error: ' . $ex->getMessage());
        }
        return false;
    }
```
## Violación Corregida (Cognitive Complexity of functions should not be too high)
### Se crearon funciones independientes para poder corregir la complejidad Cognitiva, y se tenga un buen entendimiento del código
```php
public function grabar()
{
    try {
        $db = Yii::$app->db;
        $tr = $db->beginTransaction();

        if (is_null($tr)) {
            Yii::$app->session->setFlash('error', 'No se pudo iniciar transacción.');
            return false;
        }

        // Verificar la imagen cargada
        if (!$this->verificarImagen()) {
            return false;
        }

        // Procesar el archivo Excel
        $grupo = Grupos::find()->where(['cod' => $this->grupo_cod])->one();
        $archivo = UploadedFile::getInstance($this, 'archivo');
        if (!$this->procesarArchivoExcel($archivo, $grupo, $tr)) {
            return false;
        }

        $tr->commit();
        Yii::$app->session->setFlash('success', 'Grabación exitosa.');
        return true;

    } catch (\Exception $ex) {
        if (!is_null($tr)) {
            $tr->rollBack();
        }
        Yii::$app->session->setFlash('error', 'Error: ' . $ex->getMessage());
        return false;
    }
}

private function verificarImagen()
{
    $imagen = UploadedFile::getInstance($this, 'ordenserv');
    if (empty($imagen)) {
        return false;
    }

    $extension = pathinfo($imagen->name, PATHINFO_EXTENSION);
    if (strtolower($extension) !== 'pdf') {
        Yii::$app->session->setFlash('error', 'El archivo debe ser de tipo PDF.');
        return false;
    }

    return true;
}

private function procesarArchivoExcel($archivo, $grupo, $tr)
{
    $pth = 'excel/';
    if (!is_dir($pth) && !mkdir($pth, 0777, true)) {
        $tr->rollBack();
        return false;
    }

    $tmp = $archivo->baseName . '.' . $archivo->extension;
    if (!$archivo->saveAs($pth . $tmp)) {
        Yii::$app->session->setFlash('error', 'No se pudo guardar el excel.');
        $tr->rollBack();
        return false;
    }

    if (!in_array($archivo->extension, ['xls', 'xlsx'])) {
        Yii::$app->session->setFlash('error', 'Extensión del excel incorrecta.');
        $tr->rollBack();
        return false;
    }

    $data = $this->leerDatosExcel($pth . $tmp, $grupo->cod);
    if (!$data['datosValidos']) {
        $tr->rollBack();
        Yii::$app->session->setFlash('error', 'No se encontraron datos válidos para guardar.');
        return false;
    }

    if (!empty($data['errores'])) {
        $tr->rollBack();
        return new ArrayDataProvider([
            'allModels' => $data['errores'],
            'pagination' => ['pageSize' => 1000],
            'sort' => ['attributes' => ['num_documento']]
        ]);
    }

    return true;
}

private function leerDatosExcel($filePath, $sheetName)
{
    $data = Excel::widget([
        'mode' => 'import',
        'fileName' => $filePath,
        'setFirstRecordAsKeys' => true,
        'setIndexSheetByName' => true,
        'getOnlySheet' => trim($sheetName),
    ]);

    $errores = [];
    $datosValidos = false;
    $cell = 2;

    foreach ($data as $fila) {
        if (!$this->validarFila($fila)) {
            if ($this->filaTieneDatos($fila)) {
                $fila['OBS'] = 'Fila incompleta.';
                $errores[] = $fila;
            }
            continue;
        }

        $datosValidos = true;
        $fila['FILA'] = 'A' . ($cell++);
        $fila['OBS'] = '';
        $this->procesarFila($fila, $errores);
    }

    return ['datosValidos' => $datosValidos, 'errores' => $errores];
}

private function validarFila($fila)
{
    return !(empty($fila["DOCUMENTO"]) || empty($fila["NOMBRE"]) || empty($fila["PATERNO"]) || empty($fila["MATERNO"]) || empty($fila["SEXO"]));
}

private function filaTieneDatos($fila)
{
    return !empty($fila["DOCUMENTO"]) || !empty($fila["NOMBRE"]) || !empty($fila["PATERNO"]) || !empty($fila["MATERNO"]) || !empty($fila["SEXO"]);
}

private function procesarFila(&$fila, &$errores)
{
    $alumno = Alumnos::find()->where(['num_documento' => $fila["DOCUMENTO"]])->one();
    if (is_null($alumno)) {
        $alumno = new Alumnos();
        $alumno->nombres = $fila["NOMBRE"];
        $alumno->apellidos = $fila["PATERNO"] . ' ' . $fila["MATERNO"];
        $alumno->tipo_documento = $fila["TIPODOCUMENTO"];
        $alumno->num_documento = $fila["DOCUMENTO"];
        $alumno->telefono = $fila["TELEFONO"];
        $alumno->correo = $fila["CORREO"];
        $alumno->correo2 = $fila["CORREO2"];
        $alumno->sexo = $fila["SEXO"];
        if (!$alumno->save()) {
            $fila['OBS'] = implode(', ', $alumno->getErrors());
            $errores[] = $fila;
        }
    }

    if (!is_null($alumno)) {
        $this->crearPrematricula($fila, $alumno, $errores);
    }
}

private function crearPrematricula($fila, $alumno, &$errores)
{
    $pre = new Prematricula();
    $pre->grupo_cod = $this->grupo_cod;
    $pre->pre_fdescuento = $fila["DESCUENTO"];
    $pre->monto = $this->calcularMonto($fila["DESCUENTO"]);
    $pre->id_pago = $this->id_pagos;
    $pre->id_alumno = $alumno->id;

    if (!$pre->save()) {
        $fila['OBS'] = implode(', ', $pre->getErrors());
        $errores[] = $fila;
    }
}

private function calcularMonto($descuento)
{
    return $this->pagos->curcos_fmonto * (100 - $descuento) / 100;
}
```
## Segunda Violación (Functions should not contain too many return statements)
```php
private function procesarArchivoExcel($archivo, $grupo, $tr)
    {
        $pth = 'excel/';
        if (!is_dir($pth) && !mkdir($pth, 0777, true)) {
            $tr->rollBack();
            return false;
        }

        $tmp = $archivo->baseName . '.' . $archivo->extension;
        if (!$archivo->saveAs($pth . $tmp)) {
            Yii::$app->session->setFlash('error', 'No se pudo guardar el excel.');
            $tr->rollBack();
            return false;
        }

        if (!in_array($archivo->extension, ['xls', 'xlsx'])) {
            Yii::$app->session->setFlash('error', 'Extensión del excel incorrecta.');
            $tr->rollBack();
            return false;
        }

        $data = $this->leerDatosExcel($pth . $tmp, $grupo->cod);
        if (!$data['datosValidos']) {
            $tr->rollBack();
            Yii::$app->session->setFlash('error', 'No se encontraron datos válidos para guardar.');
            return false;
        }

        if (!empty($data['errores'])) {
            $tr->rollBack();
            return new ArrayDataProvider([
                'allModels' => $data['errores'],
                'pagination' => ['pageSize' => 1000],
                'sort' => ['attributes' => ['num_documento']]
            ]);
        }

        return true;
    }
```


## Correción de Segunda Violación

```php
private function procesarArchivoExcel($archivo, $grupo, $tr)
{
    $pth = 'excel/';
    $errorMessage = ''; // Variable para acumular mensajes de error

    // Intentar crear el directorio si no existe
    if (!is_dir($pth) && !mkdir($pth, 0777, true)) {
        $errorMessage = 'No se pudo crear el directorio para guardar el archivo.';
    } else {
        $tmp = $archivo->baseName . '.' . $archivo->extension;

        // Intentar guardar el archivo
        if (!$archivo->saveAs($pth . $tmp)) {
            $errorMessage = 'No se pudo guardar el excel.';
        } elseif (!in_array($archivo->extension, ['xls', 'xlsx'])) {
            $errorMessage = 'Extensión del excel incorrecta.';
        } else {
            // Leer los datos del archivo Excel
            $data = $this->leerDatosExcel($pth . $tmp, $grupo->cod);

            // Verificar si hay datos válidos
            if (!$data['datosValidos']) {
                $errorMessage = 'No se encontraron datos válidos para guardar.';
            } elseif (!empty($data['errores'])) {
                $tr->rollBack();
                return new ArrayDataProvider([
                    'allModels' => $data['errores'],
                    'pagination' => ['pageSize' => 1000],
                    'sort' => ['attributes' => ['num_documento']]
                ]);
            }
        }
    }

    // Manejar los errores acumulados
    if ($errorMessage) {
        $tr->rollBack();
        Yii::$app->session->setFlash('error', $errorMessage);
        return false; // Solo se retorna false en caso de error
    }

    // Si todo está bien, se retorna true
    return true;
}
```
## Tercera Violación (This method has 5 returns, which is more than the 3 allowed.)

```php
public function grabar()
    {
        try {
            $db = Yii::$app->db;
            $tr = $db->beginTransaction();

            if (is_null($tr)) {
                Yii::$app->session->setFlash('error', 'No se pudo iniciar transacción.');
                return false;
            }

            // Verificar la imagen cargada
            if (!$this->verificarImagen()) {
                return false;
            }

            // Procesar el archivo Excel
            $grupo = Grupos::find()->where(['cod' => $this->grupo_cod])->one();
            $archivo = UploadedFile::getInstance($this, 'archivo');
            if (!$this->procesarArchivoExcel($archivo, $grupo, $tr)) {
                return false;
            }

            $tr->commit();
            Yii::$app->session->setFlash('success', 'Grabación exitosa.');
            return true;

        } catch (\Exception $ex) {
            if (!is_null($tr)) {
                $tr->rollBack();
            }
            Yii::$app->session->setFlash('error', 'Error: ' . $ex->getMessage());
            return false;
        }
    }
```
## Corrección de la Tercera Violación

```php
public function grabar()
{
    $db = Yii::$app->db;
    $tr = $db->beginTransaction();
    $errorMessage = ''; // Variable para acumular mensajes de error

    try {
        if (is_null($tr)) {
            $errorMessage = 'No se pudo iniciar transacción.';
        } elseif (!$this->verificarImagen()) {
            $errorMessage = 'La verificación de la imagen falló.';
        } else {
            // Procesar el archivo Excel
            $grupo = Grupos::find()->where(['cod' => $this->grupo_cod])->one();
            $archivo = UploadedFile::getInstance($this, 'archivo');
            if (!$this->procesarArchivoExcel($archivo, $grupo, $tr)) {
                $errorMessage = 'Error al procesar el archivo Excel.';
            }
        }

        // Si hubo un error acumulado
        if ($errorMessage) {
            if (!is_null($tr)) {
                $tr->rollBack();
            }
            Yii::$app->session->setFlash('error', $errorMessage);
            return false; // Retornar solo false en caso de error
        }

        // Si todo está bien, confirmar la transacción
        $tr->commit();
        Yii::$app->session->setFlash('success', 'Grabación exitosa.');
        return true;

    } catch (\Exception $ex) {
        if (!is_null($tr)) {
            $tr->rollBack();
        }
        Yii::$app->session->setFlash('error', 'Error: ' . $ex->getMessage());
        return false; // Retornar solo false en caso de excepción
    }
}
```









