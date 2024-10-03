<?php

namespace app\models\informes;

use app\models\academica\CursoPago;
use app\models\academica\Grupos;
use app\models\informes\Banco;
use app\models\informes\BancoQuery;
use app\models\tablas\Alumnos;
use app\models\tablas\DetallePago;
use Codeception\Step\Condition;
use moonland\phpexcel\Excel;
use Mpdf\Tag\Pre;
use Yii;
use yii\base\Model;
use yii\data\ArrayDataProvider;
use yii\helpers\Url;
use yii\web\UploadedFile;

class MatriculasImportarForm extends Model
{

    public $nro_operacion;
    public $banco_alumno;
    public $banco_receptor;
    public $monto;
    public $fch_pago;
    public $imagen;
    public $ordenserv;
    public $archivo;
    public $grupo_cod;
    public $id_pagos;
    public $detallePago;
    public $monto_total;

    public function rules()
    {
        return [
            [['grupo_cod', 'id_pagos', 'monto', 'fch_pago', 'monto_total'], 'required'],
            [['grupo_cod'], 'string', 'max' => 30],
            [['id_pagos'], 'default', 'value' => null],
            [['id_pagos'], 'integer'],
            [['nro_operacion'], 'safe'],
            [['banco_alumno'], 'safe'],
            [['banco_receptor'], 'safe'],
            [['monto'], 'safe'],
            [['monto_total'], 'safe'],
            [['fch_pago'], 'safe'],
            [['imagen'], 'file', 'skipOnEmpty' => true, 'extensions' => 'jpg'],
            [['ordenserv'], 'file', 'skipOnEmpty' => true, 'extensions' => 'pdf'],
            [['archivo'], 'file', 'skipOnEmpty' => false, 'extensions' => 'xls,xlsx'],
            ['grupo_cod', 'exist', 'skipOnError' => true, 'targetClass' => Grupos::class, 'targetAttribute' => ['grupo_cod' => 'cod']],
            ['id_pagos', 'exist', 'skipOnError' => true, 'targetClass' => CursoPago::class, 'targetAttribute' => ['id_pagos' => 'id']],
            ['banco_alumno', 'exist', 'skipOnError' => true, 'targetClass' => Banco::class, 'targetAttribute' => ['banco_alumno' => 'bco_vccodigo']],
            ['banco_receptor', 'exist', 'skipOnError' => true, 'targetClass' => Banco::class, 'targetAttribute' => ['banco_receptor' => 'bco_vccodigo']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [

        ];
    }

    /**
     * @return false|ArrayDataProvider|null
     */
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


    public function voucherUpload($imagen)
    {
        $path = $this->voucherPath() . $this->detallePago->voucher;
        $imagen->saveAs($path, false);
        return true;
    }

    public function voucherPath()
    {
        return Url::to('@webroot/voucher/');
    }

    public function excelUpload()
    {
        $imagen = UploadedFile::getInstance($this, 'archivo');
        if (empty($imagen)) {
            return false;
        }
        $path = $this->excelPath() . 'archivo' . ".xlsx"; // . $this->imagen->extension;
        $imagen->saveAs($path, true);
        return true;
    }

    public function excelPath()
    {
        return Url::to('@webroot/excel/');
    }
}