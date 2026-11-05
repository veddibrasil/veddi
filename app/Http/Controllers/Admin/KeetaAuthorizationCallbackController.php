<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\KeetaApiException;
use App\Http\Controllers\Controller;
use App\Services\Keeta\KeetaAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Volta do portal da Keeta depois que o lojista autoriza o app VEDDI
 * (redirectUri?authId=&state=&appId=&timestamp=). O state amarra o retorno à filial que gerou
 * o link, dentro da empresa logada; daí a tela pede pra escolher a loja da autorização.
 */
class KeetaAuthorizationCallbackController extends Controller
{
    public function __invoke(Request $request, KeetaAuthService $auth): RedirectResponse
    {
        $company = app('current.company');
        abort_unless($company->canUseKeetaIntegration(), 403);

        $state = (string) $request->query('state', '');
        $authId = (string) $request->query('authId', '');

        try {
            $integration = $auth->completeAuthorization($company->id, $state, $authId);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.settings.keeta')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::channel('keeta')->error('Keeta: falha ao concluir autorização', ['company_id' => $company->id, 'error' => KeetaApiException::forLog($e)]);

            return redirect()->route('admin.settings.keeta')->with('error', KeetaApiException::messageFor($e));
        }

        $shops = count($integration->available_shops ?? []);

        return redirect()->route('admin.settings.keeta', ['filial' => $integration->branch_id])->with('status', $shops > 0
            ? 'Autorização recebida. Escolha abaixo a loja da Keeta desta filial.'
            : 'Autorização recebida, mas a Keeta não listou nenhuma loja. Confira as lojas escolhidas no portal da Keeta.');
    }
}
