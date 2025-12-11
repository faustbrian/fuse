<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\SkeletonPhp\Example;

it('foo', function (): void {
    $example = new Example();

    $result = $example->foo();

    expect($result)->toBe('bar');
});
