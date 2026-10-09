<?php

namespace App\Exceptions;

use RuntimeException;

/** Problema do próprio produto (categoria, foto...) que impede o envio ao iFood; a mensagem vai pra tela. */
class IfoodCatalogItemException extends RuntimeException {}
