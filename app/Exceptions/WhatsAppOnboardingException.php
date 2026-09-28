<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha de negócio no onboarding do WhatsApp. A mensagem é amigável (pt-BR) e pode ser
 * exibida ao restaurante e gravada em whatsapp_connections.last_error.
 */
class WhatsAppOnboardingException extends RuntimeException {}
