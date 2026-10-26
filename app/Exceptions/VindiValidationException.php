<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Vindi recusou a cobrança por dado inválido (HTTP 422): repetir a mesma chamada falha igual.
 */
class VindiValidationException extends RuntimeException {}
