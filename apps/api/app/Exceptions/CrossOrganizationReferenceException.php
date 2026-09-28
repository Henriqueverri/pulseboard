<?php

namespace App\Exceptions;

use LogicException;

class CrossOrganizationReferenceException extends LogicException
{
    public static function for(string $model, string $relation): self
    {
        return new self("{$model} cannot reference a {$relation} from another organization.");
    }
}
