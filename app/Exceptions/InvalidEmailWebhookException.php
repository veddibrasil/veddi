<?php

namespace App\Exceptions;

use RuntimeException;

/** Webhook de e-mail sem assinatura válida (ou provedor sem webhook configurado). */
class InvalidEmailWebhookException extends RuntimeException {}
