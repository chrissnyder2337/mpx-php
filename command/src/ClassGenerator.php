<?php

namespace Lullabot\Mpx\Command;

use Lullabot\Mpx\DataService\DateTimeFormatInterface;
use Lullabot\Mpx\DataService\Media\TransferInfo;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\Parameter;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Command\Command;

abstract class ClassGenerator extends Command
{
    /**
     * Maps custom mpx field types to PHP datatypes.
     */
    public const TYPE_MAP = [
        'AvailabilityState' => 'string',
        'Boolean' => 'bool',
        'dataType[]' => 'array',
        'ContentType' => 'string',
        'DataStructure' => 'string',
        'DataType' => 'string',
        'dataType' => 'string',
        'Format' => 'string',
        'DateTime' => '\\'.DateTimeFormatInterface::class,
        'Delivery' => 'string',
        'Duration' => 'int',
        'Decimal' => 'float',
        'Expression' => 'string',
        'Float' => 'float',
        'Image' => '\\'.UriInterface::class,
        'Map<String, String>' => 'array',
        'Map<String, Integer>' => 'array',
        'Integer' => 'int',
        'Link' => '\\'.UriInterface::class,
        'Long' => 'int',
        'Map' => 'array',
        'String (64, ASCII)' => 'string',
        'String (64, Unicode)' => 'string',
        'String(64,Unicode)[]' => 'string[]',
        'String' => 'string',
        'TransferInfo' => '\\'.TransferInfo::class,
        'URI' => '\\'.UriInterface::class,
    ];

    /**
     * @param $dataType
     *
     * @return bool
     */
    protected function isScalarType($dataType): bool
    {
        return in_array($dataType, ['int', 'float', 'string', 'bool']);
    }

    /**
     * Return if the data type is an array.
     *
     * @param string $dataType
     *
     * @return bool
     */
    protected function isCollectionType($dataType): bool
    {
        $substr = substr($dataType, -2);

        return '[]' == $substr;
    }

    /**
     * @param $get
     * @param $dataType
     */
    protected function setReturnType(Method $get, $dataType): void
    {
        if ($this->isCollectionType($dataType)) {
            $get->setReturnType('array');
        } else {
            $get->setReturnType($dataType);
            if ($this->isScalarType($dataType)) {
                $get->setReturnNullable(true);
            }
        }
    }

    /**
     * @param $parameter
     * @param $dataType
     */
    protected function setTypeHint(Parameter $parameter, $dataType): void
    {
        if ($this->isCollectionType($dataType)) {
            $parameter->setTypeHint('array');
        } else {
            $parameter->setTypeHint($dataType);
            if ($this->isScalarType($dataType)) {
                $parameter->setNullable(true);
            }
        }
    }
}
