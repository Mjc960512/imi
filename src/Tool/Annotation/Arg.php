<?php

namespace Imi\Tool\Annotation;

use Imi\Bean\Annotation\Parser;
use Imi\Cli\Annotation\Option;

/**
 * 可选项注解.
 *
 * @Annotation
 * @Target("METHOD")
 * @Parser("Imi\Cli\Parser\ToolParser")
 */
class Arg extends Option
{
    /**
     * 注解别名.
     *
     * @var string|string[]
     */
    protected $__alias = Option::class;
}
