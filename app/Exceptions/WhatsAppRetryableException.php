<?php

namespace App\Exceptions;

/** Falha temporária (5xx, conexão, rate limit): o job deve tentar de novo. */
class WhatsAppRetryableException extends WhatsAppApiException {}
