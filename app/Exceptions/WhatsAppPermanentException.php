<?php

namespace App\Exceptions;

/** Falha definitiva (destinatário inválido, template inexistente...): sem retry. */
class WhatsAppPermanentException extends WhatsAppApiException {}
