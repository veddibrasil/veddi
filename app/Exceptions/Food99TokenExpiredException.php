<?php

namespace App\Exceptions;

/** errno 10102: auth_token da loja expirou (a expiração da 99 é aleatória). */
class Food99TokenExpiredException extends Food99ApiException {}
