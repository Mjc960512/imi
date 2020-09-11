<?php

namespace Imi\Model\Annotation\Relation;

use Imi\Bean\Annotation\Base;
use Imi\Bean\Annotation\Parser;

/**
 * 自动更新.
 *
 * @Annotation
 * @Target("PROPERTY")
 * @Parser("Imi\Model\Parser\RelationParser")
 */
class AutoUpdate extends Base
{
    /**
     * 只传一个参数时的参数名.
     *
     * @var string
     */
    protected ?string $defaultFieldName = 'status';

    /**
     * 是否开启.
     *
     * @var bool
     */
    public $status = true;

    /**
     * save时，删除无关联数据.
     *
     * @var bool
     */
    public $orphanRemoval = false;
}
