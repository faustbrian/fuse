<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Database\Concerns;

use Illuminate\Database\Eloquent\Model;

use function config;

/**
 * Configures primary key type based on Fuse configuration.
 *
 * This trait dynamically sets the primary key type (ID, ULID, UUID) based on
 * the fuse.primary_key_type configuration value. This allows consistent
 * key type handling across all Fuse database models.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @mixin Model
 */
trait HasFusePrimaryKey
{
    /**
     * Initialize the HasFusePrimaryKey trait for a model instance.
     *
     * Configures the primary key type and key name based on the Fuse configuration.
     * For ULID and UUID types, disables auto-incrementing since these are not
     * sequential numeric values.
     */
    public function initializeHasFusePrimaryKey(): void
    {
        $primaryKeyType = config('fuse.primary_key_type', 'id');

        if ($primaryKeyType === 'ulid' || $primaryKeyType === 'uuid') {
            $this->incrementing = false;
            $this->keyType = 'string';
        }
    }
}
