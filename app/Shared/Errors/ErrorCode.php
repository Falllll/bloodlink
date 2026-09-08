<?php

namespace App\Shared\Errors;

enum ErrorCode: string
{
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}
