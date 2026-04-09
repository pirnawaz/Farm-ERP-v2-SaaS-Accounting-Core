<?php

namespace App\Support\Concerns;

use App\Exceptions\PostedSourceDocumentImmutableException;
use Illuminate\Database\Eloquent\Model;

/**
 * Blocks arbitrary updates/deletes once status is POSTED. Allows idempotent repair
 * (posting_group_id / posted_at / redundant status=POSTED) used by posting services.
 */
trait GuardsPostedRecordImmutability
{
    public static function bootGuardsPostedRecordImmutability(): void
    {
        static::updating(function (Model $model) {
            if (! $model->isDirty()) {
                return;
            }
            if ($model->getOriginal('status') !== static::STATUS_POSTED) {
                return;
            }

            $dirty = $model->getDirty();
            unset($dirty['updated_at']);

            foreach (array_keys($dirty) as $key) {
                if (static::isAllowedPostedFieldChange($model, $key)) {
                    continue;
                }
                throw new PostedSourceDocumentImmutableException;
            }
        });

        static::deleting(function (Model $model) {
            if ($model->getAttribute('status') === static::STATUS_POSTED) {
                throw new PostedSourceDocumentImmutableException('Posted records cannot be deleted.');
            }
        });
    }

    protected static function isAllowedPostedFieldChange(Model $model, string $key): bool
    {
        if (in_array($key, ['posting_group_id', 'posted_at'], true)) {
            return true;
        }

        if ($key === 'status') {
            return $model->getAttribute('status') === static::STATUS_POSTED
                && $model->getOriginal('status') === static::STATUS_POSTED;
        }

        return false;
    }
}
