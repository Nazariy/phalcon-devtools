<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-present Phalcon Team (https://www.phalconphp.com)   |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  |          Serghei Iakovlev <serghei@phalconphp.com>                     |
  +------------------------------------------------------------------------+
*/

namespace Phalcon\Builder;

use Nette\PhpGenerator\ClassType;
use Phalcon\Db;
use Phalcon\Db\Adapter\Pdo;
use Phalcon\Db\Column;
use Phalcon\Db\Reference;
use Phalcon\Exception\InvalidArgumentException;
use Phalcon\Exception\InvalidParameterException;
use Phalcon\Exception\RuntimeException;
use Phalcon\Exception\WriteFileException;
use Phalcon\Generator\Snippet;
use Phalcon\Options\OptionsAware as ModelOption;
use Phalcon\Text;
use Phalcon\Utils;
use Phalcon\Version;
use ReflectionClass;

/**
 * ModelBuilderComponent
 *
 * Builder to generate models
 *
 * @package Phalcon\Builder
 */
class Model extends Component
{
    /**
     * @var string
     */
    protected $table;
    /**
     * @var null|string
     */
    protected $schema;
    /**
     * @var string
     */
    protected $fullClassName;
    /**
     * Map of scalar data objects
     * @var array
     */
    private $typeMap = [
        //'Date' => 'Date',
        //'Decimal' => 'Decimal'
    ];

    /**
     * Options container
     * @var ModelOption
     */
    protected $modelOptions;

    /**
     * @var \Nette\PhpGenerator\PhpFile
     */
    protected $file;
    /**
     * @var \Phalcon\Db\Adapter\Pdo\Mysql
     */
    protected $db;
    protected $namespace;
    protected $class;
    protected $source = [];

    /**
     * Create Builder object
     *
     * @param array $options
     * @throws InvalidArgumentException
     * @throws BuilderException
     */
    public function __construct(array $options)
    {
        $this->modelOptions = new ModelOption($options);
        $this->file = new \Nette\PhpGenerator\PhpFile;

        if (!$this->modelOptions->hasOption('name')) {
            throw new InvalidArgumentException('Please, specify the table name');
        }

        $safeName = Utils::lowerCamelizeWithDelimiter($options['name'], '_-');

        $this->modelOptions->setNotDefinedOption('camelize', false);
        $this->modelOptions->setNotDefinedOption('force', false);
        $this->modelOptions->setNotDefinedOption('className', $safeName);
        $this->modelOptions->setNotDefinedOption('fileName', $safeName);
        $this->modelOptions->setNotDefinedOption('abstract', false);
        $this->modelOptions->setNotDefinedOption('annotate', false);

        parent::__construct($options);

        $this->modelOptions->setOption('config', $this->modelOptions->getOption('config'));
        $this->modelOptions->setOption('snippet', new Snippet());

        $exclude = [];
        if ($this->modelOptions->hasOption('excludeFields')) {
            foreach (explode(',', $this->modelOptions->getOption('excludeFields')) as $key) {
                $exclude[] = trim($key);
            }
        }
        $this->modelOptions->setOption('excludeFields', $exclude);

        $config = $this->modelOptions->getOption('config');
        $this->checkDataBaseParam();
        $this->isSupportedAdapter($config->database->adapter);
        $adapter = 'Mysql';
        if (isset($config->database->adapter)) {
            $adapter = $config->database->adapter;
        }

        if (is_object($config->database)) {
            $configArray = $config->database->toArray();
        } else {
            $configArray = $config->database;
        }

        $adapterName = 'Phalcon\Db\Adapter\Pdo\\' . $adapter;
        unset($configArray['adapter']);
        /** @var Pdo $db */
        $this->db = new $adapterName($configArray);

        $this->table = $this->modelOptions->getOption('name');

        if ($this->modelOptions->hasOption('schema')) {
            $this->schema = $this->modelOptions->getOption('schema');
        } else {
            $this->schema = Utils::resolveDbSchema($config->database);
        }
        if (!$this->db->tableExists($this->table, $this->schema)) {
            throw new InvalidArgumentException(sprintf('Table "%s" does not exist.', $this->table));
        }


        if ($this->modelOptions->hasOption('namespace')) {
            $this->namespace = $this->file->addNamespace($this->modelOptions->getOption('namespace'));
            $this->class = $this->namespace->addClass($this->modelOptions->getOption('className'));
            $this->fullClassName = $this->modelOptions->getOption('namespace') . '\\' . $this->modelOptions->getOption('className');
            $this->class->addComment(sprintf('@package %s', $this->modelOptions->getOption('namespace')));
        } else {
            $this->namespace = $this->file->addNamespace(null);
            $this->fullClassName = $this->modelOptions->getOption('className');
            $this->class = $this->namespace->addClass($this->modelOptions->getOption('className'));
        }
        $this->class->addComment(null);
    }

    public function setFileSource($fileName)
    {
        $this->source = file($fileName);
        return $this;
    }

    /**
     * getFields
     * @return Column[]
     */
    private function getFields()
    {
        static $fields;
        if ($fields === null) {
            $fields = $this->db->describeColumns($this->table, $this->schema);
        }
        return $fields;
    }

    /**
     * getModelRelation
     * @param ClassType $class
     * @param Reference $reference
     * @param null|string $tableName
     * @return string
     */
    protected function getModelRelation(Reference $reference, $tableName = null)
    {
        /** @var \Phalcon\Generator\Snippet $snippet */
        $snippet = $this->modelOptions->getOption('snippet');
        $namespace = null;
        if ($this->modelOptions->hasOption('namespace')) {
            $namespace = $this->modelOptions->getOption('namespace');
        }

        $column1 = current($tableName ? $reference->getReferencedColumns() : $reference->getColumns());
        $column2 = current($tableName ? $reference->getColumns() : $reference->getReferencedColumns());

        if ($this->modelOptions->getOption('camelize')) {
            $column1 = Utils::lowerCamelize($column1);
            $column2 = Utils::lowerCamelize($column2);
        }

        $className = Text::camelize(Text::uncamelize($tableName ?: $reference->getReferencedTable()), '_-');


        if ($tableName) {
            $this->class->addComment('@property \Phalcon\Mvc\Model\Resultset\Simple ' . Utils::lowerCamelize($tableName));
        } else {
            $this->class->addComment(sprintf('@property %s\%s %s', $namespace, $className,
                Utils::lowerCamelize($className)));
        }

        return $snippet->getRelation(
            $tableName === null ? 'belongsTo' : 'hasMany',
            $column1,
            "{$namespace}\\{$className}::class",
            $column2,
            "['alias' => '" . Utils::lowerCamelize($className) . "']"
        );
    }

    private function _classDescription()
    {
        if (file_exists('license.txt')) {
            $this->file->setComment(trim(file_get_contents('license.txt')));
        }
        $this->class->addComment('@autogenerated by Phalcon Developer Tools');
        $this->class->addComment(sprintf('@date %s', date('Y-m-d, H:i:s')));
        $this->class->addComment(null);

        $this->class->setAbstract($this->modelOptions->getOption('abstract'));
        $this->class->setExtends(
            $this->modelOptions->getValidOptionOrDefault('extends', \Phalcon\Mvc\Model::class)
        );
        return $this;
    }

    private function _methodGetSource()
    {
        $this->class
            ->addMethod('getSource')
            ->addComment('Returns table name mapped in the model.')
            ->addComment('@return string')
            ->addBody(sprintf('return \'%s\';', $this->modelOptions->getOption('name')));

        return $this;
    }

    private function _methodFind()
    {
        $method = $this->class
            ->addMethod('find')
            ->setStatic(true)
            ->addComment('Allows to query a set of records that match the specified conditions')
            ->addComment(null)
            ->addComment('@param mixed $parameters')
            ->addComment('@return self[]|self|\Phalcon\Mvc\Model\ResultSetInterface')
            ->addBody('return parent::find($parameters);');
        $method->addParameter('parameters')->setNullable()->setOptional();
        return $method;
    }

    private function _methodFindFirst()
    {
        $method = $this->class
            ->addMethod('findFirst')
            ->setStatic(true)
            ->addComment('Allows to query the first record that match the specified conditions')
            ->addComment(null)
            ->addComment('@param mixed $parameters')
            ->addComment('@return self|\Phalcon\Mvc\Model\ResultInterface')
            ->addBody('return parent::findFirst($parameters);');
        $method->addParameter('parameters')->setNullable()->setOptional();
        return $method;
    }

    /**
     * _methodInitialize
     * @return $this
     */
    private function _methodInitialize()
    {
        $method = $this->class->addMethod('initialize');
        $method->addComment('Initialize method for model.');
        $method->addComment(null);
        $method->addComment('@return void');

        if ($this->modelOptions->hasOption('extends')) {
            $method->addBody('parent::initialize();');
        }

        if ($this->schema) {
            $method->addBody(sprintf(
                '$this->%s(\'%s\');',
                'setSchema',
                $this->schema
            ));
        }
        $method->addBody(sprintf(
            '$this->%s(\'%s\');',
            'setSource',
            $this->modelOptions->getOption('name')
        ));

        foreach ($this->getReferenceList($this->schema, $this->db, $this->table) as $tableName => $references) {
            /** @var Reference $reference */
            foreach ($references as $reference) {
                if ($reference->getReferencedTable() === $this->table) {
                    $method->addBody($this->getModelRelation($reference, $tableName));
                }
            }
        }
        foreach ($this->db->describeReferences($this->table, $this->schema) as $reference) {
            $method->addBody($this->getModelRelation($reference));
        }
        return $this;
    }

    private function _methodFields()
    {
        /** @var \Phalcon\Generator\Snippet $snippet */
        $snippet = $this->modelOptions->getOption('snippet');

        $useSettersGetters = $this->modelOptions->getValidOptionOrDefault('genSettersGetters', false);
        $validator = $this->class
            ->addMethod('validation')
            ->addComment('Validations and business logic')
            ->addComment('@return boolean')
            ->addBody('$validator = new \Phalcon\Validation();');

        foreach ($this->getFields() as $field) {
            if (in_array($field->getName(), $this->modelOptions->getOption('excludeFields'), false)) {
                continue;
            }

            $type = $this->getPHPType($field->getType());

            $fieldName = Utils::lowerCamelize(
                Utils::camelize($field->getName(), $this->modelOptions->getOption('camelize') ? '_-' : '-')
            );

            $property = $this->class->addProperty($fieldName);
            $property->addComment(sprintf('@var %s', $type));
            $property->setVisibility($useSettersGetters ? 'protected' : 'public');

            if ($this->modelOptions->getOption('annotate')) {
                if ($field->isPrimary()) {
                    $property->addComment('@Primary');
                }
                if ($field->isAutoIncrement()) {
                    $property->addComment('@Identity');
                }
                $property->addComment(sprintf(
                    '@Column(column="%s", type="%s"%s, nullable=%s)',
                    $field->getName(),
                    $type,
                    $field->getSize() ? ', length=' . $field->getSize() : '',
                    $field->isNotNull() ? 'false' : 'true'
                ));
            }

            if ($useSettersGetters) {
                $methodName = Utils::camelize($field->getName(), '_-');
                $setter = $this->class->addMethod(sprintf('set%s', $methodName));
                $setter->addComment(sprintf('Method to set the value of field %s', $field->getName()));
                $setter->addComment(sprintf('@param %s $%s', $type, $fieldName));
                $setter->addComment('@return $this');
                $setter
                    ->addParameter($fieldName)
                    ->setTypeHint($type)
                    ->setNullable(!$field->isNotNull())
                    ->setOptional(!$field->isNotNull());

                $setter->addBody(sprintf('$this->%s = $%s;', $fieldName, $fieldName));
                $setter->addBody('return $this;');
                $setter->setReturnType('self');

                $getter = $this->class->addMethod(sprintf('get%s', $methodName));
                $getter->addComment(sprintf('Returns the value of field %s', $field->getName()));
                $getter->addComment(sprintf('@return %s', $type));
                $getter->setReturnType($type);
                $getter->setReturnNullable(!$field->isNotNull());

                if (isset($this->typeMap[$type])) {
                    $getter->addBody(sprintf(
                        'return $this->%1$s ? new %2$s($this->%1$s) : null;',
                        $field->getName(),
                        $this->typeMap[$type]
                    ));
                } else {
                    $getter->addBody(sprintf('return $this->%s;', $field->getName()));
                }
            }

            if ($field->getType() === Column::TYPE_CHAR) {
                $options = $field->getTypeValues();
                if ($options === null && Version::getPart(Version::VERSION_MAJOR) === 3) {
                    /**
                     * This is a workaround for ENUM support
                     */
                    switch (true) {
                        case $this->db instanceof Db\Adapter\Pdo\Mysql:
                            /** @var \stdClass $result */
                            $result = $this->db->fetchOne(
                                "SHOW COLUMNS FROM `{$this->table}` WHERE field = '{$field->getName()}'",
                                Db::FETCH_OBJ
                            );
                            $options = $result->Type;
                            break;
                        case $this->db instanceof Db\Adapter\Pdo\Postgresql:
                            //not implemented
                            break;
                        case $this->db instanceof Db\Adapter\Pdo\Sqlite:
                            //not implemented
                            break;
                    }
                }

                $domain = [];
                if (preg_match('/\((.*)\)/', $options, $matches)) {
                    foreach (explode(',', $matches[1]) as $item) {
                        $domain[] = $item;
                    }
                }


                if (count($domain)) {
                    $validator->addBody($snippet->getValidateInclusion($fieldName, implode(', ', $domain)));
                }
            }

            if ($field->getName() === 'email') {
                $validator->addBody($snippet->getValidateEmail($fieldName));
            }
        }
        $validator->addBody('return $this->validate($validator);');
    }

    /**
     * _methodColumnMap
     * @return $this
     */
    private function _methodColumnMap()
    {
        if ($this->modelOptions->hasOption('mapColumn') && $this->modelOptions->getOption('mapColumn')) {

            $camelize = $this->modelOptions->getOption('camelize');
            $array = [];
            foreach ($this->getFields() as $column) {
                $array[] = sprintf(
                    '\'%s\' => \'%s\'',
                    $column->getName(),
                    $camelize ? Utils::lowerCamelize($column->getName()) : $column->getName()
                );
            }

            $method = $this->class->addMethod('columnMap');
            $method->addComment('Independent Column Mapping.');
            $method->addComment('Keys are the real names in the table and the values their names in the application');
            $method->addComment(null);
            $method->addComment('@return array');
            $method->setReturnType('array');
            $method->addBody('return [');
            $method->addBody('    ' . implode(",\n    ", $array));
            $method->addBody('];');
        }
        return $this;
    }

    /**
     * _insertReflectionMethod
     * @param \ReflectionMethod $reflection
     * @return bool
     * @throws \ReflectionException
     */
    private function _insertReflectionMethod(\ReflectionMethod $reflection)
    {
        /**
         * Ignore rules
         */
        switch (true) {
            case $reflection->isInternal():
            case $reflection->getFileName() !== $this->modelOptions->getOption('modelPath'):
            case $reflection->getDeclaringClass()->getName() !== $this->fullClassName:
            case isset($this->class->methods[$reflection->getName()]):
                return false;
        }

        $method = $this->class->addMethod($reflection->getName());
        $method->setStatic($reflection->isStatic());
        $method->setAbstract($reflection->isAbstract());
        $method->setFinal($reflection->isFinal());
        switch (true) {
            case $reflection->isPrivate():
                $method->setVisibility('private');
                break;
            case $reflection->isProtected():
                $method->setVisibility('protected');
                break;
        }

        if ($reflection->hasReturnType() && ($type = $reflection->getReturnType()) instanceof \ReflectionType) {
            $method->setReturnType($type->getName());
            $method->setReturnNullable($type->allowsNull());
        }

        if ($reflection->getDocComment()) {
            $comments = explode(PHP_EOL, trim($reflection->getDocComment(), '/'));
            array_walk($comments, function ($value) use ($method) {
                $value = trim(ltrim(trim($value), '*'));
                if (!empty($value)) {
                    $method->addComment($value);
                }
            });
        }
        /** @var \ReflectionParameter $attr */
        foreach ($reflection->getParameters() as $attr) {
            $parameter = $method->addParameter($attr->getName());
            $parameter->setOptional($attr->isOptional());
            $parameter->setNullable($attr->allowsNull());
            $parameter->setReference($attr->isPassedByReference());
            if ($attr->hasType() && ($type = $attr->getType()) instanceof \ReflectionType) {
                $parameter->setTypeHint($type->getName());
            }
            $parameter->setDefaultValue($attr->getDefaultValue());
        }

        $body = \array_slice(
            $this->source,
            $reflection->getStartLine(),
            $reflection->getEndLine() - $reflection->getStartLine()
        );
        array_walk($body, function (&$line, $i) {
            $line = rtrim($line);
            switch (true) {
                case strpos($line, str_repeat(chr(011), 2)) === 0:
                    $line = substr($line, 2);
                    break;
                case strpos($line, str_repeat(chr(011), 1)) === 0:
                    $line = substr($line, 1);
                    break;
                case strpos($line, str_repeat(chr(040), 8)) === 0:
                    $line = substr($line, 8);
                    break;
                case strpos($line, str_repeat(chr(040), 4)) === 0:
                    $line = substr($line, 4);
                    break;
            }
        });
        $str = rtrim(ltrim(trim(implode(PHP_EOL, $body)), '{'), '}');
        $method->addBody($str);
        return true;
    }

    /**
     * _insertReflectionConstant
     * @param \ReflectionClassConstant $reflection
     * @return bool
     */
    private function _insertReflectionConstant(\ReflectionClassConstant $reflection)
    {
        /**
         * Ignore rules
         */
        switch (true) {
            case $reflection->getDeclaringClass()->getName() !== $this->fullClassName:
            case isset($this->class->getConstants()[$reflection->getName()]):
                return false;
        }

        $constant = $this->class->addConstant($reflection->getName(), $reflection->getValue());

        switch (true) {
            case $reflection->isPrivate():
                $constant->setVisibility('private');
                break;
            case $reflection->isProtected():
                $constant->setVisibility('protected');
                break;
        }

        if ($reflection->getDocComment()) {
            $comments = explode(PHP_EOL, trim($reflection->getDocComment(), '/'));
            array_walk($comments, function ($value) use ($constant) {
                $value = trim(ltrim(trim($value), '*'));
                if (!empty($value)) {
                    $constant->addComment($value);
                }
            });
        }
        return true;
    }

    /**
     * _insertReflectionProperty
     * @param \ReflectionProperty $reflection
     * @param mixed $default
     * @return bool
     */
    private function _insertReflectionProperty(\ReflectionProperty $reflection, $default = null)
    {
        /**
         * Ignore rules
         */
        switch (true) {
            case $reflection->getDeclaringClass()->getName() !== $this->fullClassName:
            case isset($this->class->getProperties()[$reflection->getName()]):
                return false;
        }
        $reflection->setAccessible(true);
        $property = $this->class->addProperty($reflection->getName());
        $property->setStatic($reflection->isStatic());
        $property->setValue($default);
        switch (true) {
            case $reflection->isPrivate():
                $property->setVisibility('private');
                break;
            case $reflection->isProtected():
                $property->setVisibility('protected');
                break;
        }
        if ($reflection->getDocComment()) {
            $comments = explode(PHP_EOL, trim($reflection->getDocComment(), '/'));
            array_walk($comments, function ($value) use ($property) {
                $value = trim(ltrim(trim($value), '*'));
                if (!empty($value)) {
                    $property->addComment($value);
                }
            });
        }

        return true;
    }

    /**
     * Module build
     *
     * @return mixed
     * @throws BuilderException
     */
    public function build()
    {
        $config = $this->modelOptions->getOption('config');

        if (isset($config->devtools->loader)) {
            /** @noinspection PhpIncludeInspection */
            require_once $config->devtools->loader;
        }

        if ($this->modelOptions->hasOption('directory')) {
            $this->path->setRootPath($this->modelOptions->getOption('directory'));
        }
        $this->setModelsDir();
        $this->setModelPath();

        /**
         * BUILD CLASS
         */
        $this->_classDescription();
        $this->_methodInitialize();
        $this->_methodGetSource();
        $this->_methodFields();
        $this->_methodFind();
        $this->_methodFindFirst();
        $this->_methodColumnMap();


        /**
         * Apply constants, properties and methods from existing class
         */
        $modelPath = $this->modelOptions->getOption('modelPath');

        if (file_exists($modelPath)) {
            try {

                /** @noinspection PhpIncludeInspection */
                require_once $modelPath;

                $this->setFileSource($modelPath);

                $reflection = new ReflectionClass($this->fullClassName);

                $this->class->setAbstract($reflection->isAbstract());
                $this->class->setFinal($reflection->isFinal());

                foreach($reflection->getTraits() as $trait){
                    $this->class->addTrait($trait->getName());
                }

                foreach ($reflection->getReflectionConstants() as $constant) {
                    $this->_insertReflectionConstant($constant);
                }

                $defaults = $reflection->getDefaultProperties();
                foreach ($reflection->getProperties() as $property) {

                    $this->_insertReflectionProperty($property, $defaults[$property->getName()]);
                }

                foreach ($reflection->getMethods() as $method) {
                    $this->_insertReflectionMethod($method);
                }

            } catch (\Exception $e) {
                throw new RuntimeException(
                    sprintf(
                        'Failed to create the model "%s". Error: %s',
                        $this->modelOptions->getOption('className'),
                        $e->getMessage()
                    )
                );
            }
        }
//        highlight_string($this->file);
//
//        $content = '';
//        exit;
        if (file_exists($modelPath) && !is_writable($modelPath)) {
            throw new WriteFileException(sprintf('Unable to write to %s. Check write-access of a file.', $modelPath));
        }

        if (!file_put_contents($modelPath, $this->file)) {
            throw new WriteFileException(sprintf('Unable to write to %s', $modelPath));
        }

        if ($this->isConsole()) {
            $msgSuccess = ($this->modelOptions->getOption('abstract') ? 'Abstract ' : '');
            $msgSuccess .= 'Model "%s" was successfully created.';
            $this->notifySuccess(sprintf($msgSuccess, Text::camelize($this->modelOptions->getOption('name'), '_-')));
        }
    }

    /**
     * Set path to model
     *
     * @throw WriteFileException
     */
    protected function setModelPath()
    {
        $modelPath = $this->modelOptions->getOption('modelsDir');

        if (false === $this->isAbsolutePath($modelPath)) {
            $modelPath = $this->path->getRootPath($modelPath);
        }

        $modelPath .= $this->modelOptions->getOption('className') . '.php';

        if (file_exists($modelPath) && !$this->modelOptions->getOption('force')) {
            throw new WriteFileException(sprintf(
                'The model file "%s.php" already exists in models dir',
                $this->modelOptions->getOption('className')
            ));
        }

        $this->modelOptions->setOption('modelPath', $modelPath);
    }

    /**
     * @throw InvalidParameterException
     */
    protected function checkDataBaseParam()
    {
        if (!isset($this->modelOptions->getOption('config')->database)) {
            throw new InvalidParameterException('Database configuration cannot be loaded from your config file.');
        }

        if (!isset($this->modelOptions->getOption('config')->database->adapter)) {
            throw new InvalidParameterException(
                'Adapter was not found in the config. ' .
                'Please specify a config variable [database][adapter]'
            );
        }
    }

    /**
     * Get reference list from option
     *
     * @param string $schema
     * @param Pdo $db
     * @param null $table
     * @return array
     */
    protected function getReferenceList($schema, Pdo $db, $table = null)
    {
        if ($this->modelOptions->hasOption('referenceList')) {
            return $this->modelOptions->getOption('referenceList');
        }
        $tables = [];
        if ($db instanceof \Phalcon\Db\Adapter\Pdo\Mysql) {

            $results = $db->fetchAll(
                '
                SELECT m.TABLE_NAME, m.REFERENCED_TABLE_NAME 
                FROM information_schema.KEY_COLUMN_USAGE AS m 
                WHERE m.TABLE_SCHEMA = :schema AND ( m.TABLE_NAME = :table OR m.REFERENCED_TABLE_NAME = :table)
                ',
                \Phalcon\Db::FETCH_ASSOC,
                ['schema' => $schema, 'table' => $table]
            );
            foreach ($results as $v) {
                if (isset($v['TABLE_NAME'])) {
                    $tables[] = $v['TABLE_NAME'];
                }
                if (isset($v['REFERENCED_TABLE_NAME'])) {
                    $tables[] = $v['REFERENCED_TABLE_NAME'];
                }
            }
            $tables = array_unique($tables);

        } else {
            $tables = $db->listTables($schema);
        }

        $referenceList = [];
        foreach ($tables as $name) {
            $referenceList[$name] = $db->describeReferences($name, $schema);
        }

        return $referenceList;
    }

    /**
     * Set path to folder where models are
     *
     * @throw InvalidParameterException
     */
    protected function setModelsDir()
    {
        if ($this->modelOptions->hasOption('modelsDir')) {
            $this->modelOptions->setOption(
                'modelsDir',
                rtrim($this->modelOptions->getOption('modelsDir'), '/\\') . DIRECTORY_SEPARATOR
            );
            return;
        }

        if ($modelsDir = $this->modelOptions->getOption('config')->path('application.modelsDir')) {
            $this->modelOptions->setOption('modelsDir', rtrim($modelsDir, '/\\') . DIRECTORY_SEPARATOR);
            return;
        }

        throw new InvalidParameterException("Builder doesn't know where is the models directory.");
    }

    /**
     * Returns the associated PHP type
     *
     * @param  string $type
     * @return string
     */
    protected function getPHPType($type)
    {
        switch ($type) {
            case Column::TYPE_BOOLEAN:
                return 'bool';
                break;
            case Column::TYPE_INTEGER:
            case Column::TYPE_BIGINTEGER:
                return 'int';
                break;
            case Column::TYPE_DECIMAL:
            case Column::TYPE_FLOAT:
                return 'float';
                break;
            case Column::TYPE_DATE:
            case Column::TYPE_VARCHAR:
            case Column::TYPE_DATETIME:
            case Column::TYPE_CHAR:
            case Column::TYPE_TEXT:
                return 'string';
                break;
            default:
                return 'string';
                break;
        }
    }
}
