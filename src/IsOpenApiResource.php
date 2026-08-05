<?php

declare(strict_types=1);

namespace Vitis\RestDB\OpenApi;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

/**
 * Composes the core RestDB trait with defaults suited to a plain OpenAPI REST
 * resource: server-assigned, non-incrementing string keys. Generated models own
 * these — flip $incrementing / $keyType in the committed class if your API
 * hands out integer ids.
 *
 * @phpstan-require-extends Model
 */
trait IsOpenApiResource
{
    use InteractsWithRestApi;

    public function initializeIsOpenApiResource(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
