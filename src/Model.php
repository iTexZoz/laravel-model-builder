<?php

namespace Jimbolino\Laravel\ModelBuilder;

use Exception;
use ReflectionClass;

/**
 * Class Model, a representation of one Laravel model.
 */
class Model
{
    // input
    private $baseModel = 'Model';
    private $table = '';
    private $foreignKeys = [];

    // the class and table names
    private $class = '';

    // auto detected the elements
    private $timestampFields = [];
    private $primaryKey = '';
    private $incrementing = false;
    private $timestamps = false;
    private $dates = [];
    private $hidden = [];
    private $enum = [];
    private $casts = [];
    private $fillable = [];
    private $namespace = '';

    /**
     * @var Relations
     */
    private $relations;

    // the result
    private $fileContents = '';

    /**
     * First build the model.
     *
     * @param $table
     * @param $baseModel
     * @param $describes
     * @param $foreignKeys
     * @param string $namespace
     * @param string $prefix
     */
    public function buildModel($table, $baseModel, $describes, $foreignKeys, $namespace = '', $prefix = '')
    {
        $this->table = StringUtils::removePrefix($table, $prefix);
        $this->baseModel = $baseModel;
        $this->foreignKeys = $this->filterAndSeparateForeignKeys($foreignKeys['all'], $table);
        $foreignKeysByTable = $foreignKeys['ordered'];

        if (!empty($namespace)) {
            $this->namespace = ' namespace '.$namespace.';';
        }

        $this->class = StringUtils::prettifyTableName($table, $prefix);
        $this->timestampFields = $this->getTimestampFields($this->baseModel);

        $describe = $describes[$table];

        // main loop
        foreach ($describe as $field) {
            if ($this->isPrimaryKey($field)) {
                $this->primaryKey = $field->Field;
                $this->incrementing = $this->isIncrementing($field);
                continue;
            }

            if ($this->isTimestampField($field)) {
                $this->timestamps = true;
                continue;
            }

            if ($this->isJson($field)) {
                $this->casts[$field->Field] = 'json';
            }

            if ($this->isEnum($field)) {
                $variables = $field->Type;
                $enums = explode(',', trim(explode(')', explode('enum(', $variables)[1])[0]));
                foreach ($enums as &$enum) {
                    $enum = trim($enum, "'");
                }
                unset($enum);
                $this->enum[$field->Field] = $enums;
            }

            if ($this->isDate($field)) {
                $this->dates[] = $field->Field;
            }

            if ($this->isHidden($field)) {
                $this->hidden[] = $field->Field;
                continue;
            }

            if ($this->isForeignKey($table, $field->Field)) {
                continue;
            }

            $this->fillable[] = $field->Field;
        }

        // relations
        $this->relations = new Relations(
            $table,
            $this->foreignKeys,
            $describes,
            $foreignKeysByTable,
            $prefix,
            $namespace
        );
    }

    /**
     * Secondly, create the model.
     */
    public function createModel()
    {
        $file = '<?php'.$this->namespace.LF.LF;

        $file .= '/**'.LF;
        $file .= ' * Eloquent class to describe the '.$this->table.' table'.LF;
        $file .= ' *'.LF;
        $file .= ' * automatically generated by ModelGenerator.php'.LF;
        $file .= ' */'.LF;

        // a new class that extends the provided baseModel
        $file .= 'class '.$this->class.' extends '.$this->baseModel.LF;
        $file .= '{'.LF;

        foreach ($this->enum as $fieldName => $field) {
            foreach ($field as $const) {
                $key = strtoupper($fieldName).'_'.strtoupper($const);
                $file .= TAB.'const '.$key.' = '.StringUtils::export($const).';'.LF;
            }
            $file .= LF;
        }

        // the name of the mysql table
        $file .= TAB.'protected $table = '.StringUtils::export($this->table).';'.LF.LF;

        // primary key defaults to "id"
        if ($this->primaryKey !== 'id') {
            $file .= TAB.'public $primaryKey = '.StringUtils::export($this->primaryKey).';'.LF.LF;
        }

        // timestamps defaults to true
        if (!$this->timestamps) {
            $file .= TAB.'public $timestamps = '.StringUtils::export($this->timestamps).';'.LF.LF;
        }

        // incrementing defaults to true
        if (!$this->incrementing) {
            $file .= TAB.'public $incrementing = '.StringUtils::export($this->incrementing).';'.LF.LF;
        }

        // add casts
        if (!empty($this->casts)) {
            $file .= TAB.'protected $casts = '.StringUtils::export($this->casts, TAB).';'.LF.LF;
        }

        // most fields are considered as fillable
        $wrap = TAB.'protected $fillable = '.StringUtils::export($this->fillable).';'.LF.LF;
        $file .= wordwrap($wrap, ModelGenerator::$lineWrap, LF.TAB.TAB);

        // except for the hidden ones
        if (!empty($this->hidden)) {
            $file .= TAB.'protected $hidden = '.StringUtils::export($this->hidden).';'.LF.LF;
        }

        // all date fields
        if (!empty($this->dates)) {
            $file .= TAB.'public function getDates()'.LF;
            $file .= TAB.'{'.LF;
            $file .= TAB.TAB.'return '.StringUtils::export($this->dates).';'.LF;
            $file .= TAB.'}'.LF.LF;
        }

        // add all relations
        $file .= rtrim($this->relations).LF; // remove one LF from end

        // close the class, remove excess LFs
        $file = rtrim($file).LF.'}'.LF;

        $this->fileContents = $file;
    }

    /**
     * Thirdly, return the created string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->fileContents;
    }

    /**
     * Detect if we have timestamp field
     * TODO: not sure about this one yet.
     *
     * @param $model
     *
     * @return array
     */
    protected function getTimestampFields($model)
    {
        try {
            $baseModel = new ReflectionClass($model);
            $timestampFields = [
                'created_at' => $baseModel->getConstant('CREATED_AT'),
                'updated_at' => $baseModel->getConstant('UPDATED_AT'),
                'deleted_at' => $baseModel->getConstant('DELETED_AT'),
            ];
        } catch (Exception $e) {
            echo 'baseModel: '.$model.' not found'.LF;
            $timestampFields = [
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'deleted_at' => 'deleted_at',
            ];
        }

        return $timestampFields;
    }

    /**
     * Check if the field is primary key.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isPrimaryKey($field)
    {
        return $field->Key === 'PRI';
    }

    /**
     * Check if the field (primary key) is auto incrementing.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isIncrementing($field)
    {
        return $field->Extra === 'auto_increment';
    }

    /**
     * Check if we have timestamp field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isTimestampField($field)
    {
        return array_search($field->Field, $this->timestampFields);
    }

    /**
     * Check if we have a json field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isJson($field)
    {
        return StringUtils::strContains(['json'], $field->Type);
    }

    /**
     * Check if we have a enum field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isEnum($field)
    {
        return StringUtils::strContains(['enum'], $field->Type);
    }

    /**
     * Check if we have a date field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isDate($field)
    {
        return StringUtils::strContains(['date', 'time', 'year'], $field->Type);
    }

    /**
     * Check if we have a hidden field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isHidden($field)
    {
        return StringUtils::strContains(['hidden', 'secret'], $field->Comment);
    }

    /**
     * Check if we have a foreign key.
     *
     * @param $table
     * @param $field
     *
     * @return bool
     */
    protected function isForeignKey($table, $field)
    {
        foreach ($this->foreignKeys['local'] as $entry) {
            if ($entry->COLUMN_NAME == $field && $entry->TABLE_NAME == $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only show the keys where table is mentioned.
     *
     * @param $foreignKeys
     * @param $table
     *
     * @return array
     */
    protected function filterAndSeparateForeignKeys($foreignKeys, $table)
    {
        $results = ['local' => [], 'remote' => []];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->TABLE_NAME == $table) {
                $results['local'][] = $foreignKey;
            }
            if ($foreignKey->REFERENCED_TABLE_NAME == $table) {
                $results['remote'][] = $foreignKey;
            }
        }

        return $results;
    }
}
