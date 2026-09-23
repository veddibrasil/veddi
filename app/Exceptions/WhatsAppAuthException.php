<?php

namespace App\Exceptions;

/** Token inválido/expirado ou permissão revogada: a conexão precisa ser refeita. */
class WhatsAppAuthException extends WhatsAppApiException {}
