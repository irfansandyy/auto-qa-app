<?php

namespace App\Http\Controllers;

use App\Services\ModelClient;
use App\Services\ModelEndpointPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ModelSettingsController extends Controller
{
    public function update(Request $request, ModelEndpointPolicy $endpoints): RedirectResponse
    {
        $apiKey = $request->input('model_api_key');
        $request->replace($request->except('model_api_key'));

        $settings = $request->validate([
            'model_provider' => ['required', 'string', Rule::in(ModelClient::PROVIDERS)],
            'model_name' => ['nullable', 'required_unless:model_provider,local', 'string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,149}\z/'],
            'model_base_url' => ['nullable', 'required_if:model_provider,custom', 'string', 'max:2048', 'url:https'],
            'forget_api_key' => ['sometimes', 'boolean'],
        ], [], [
            'model_provider' => __('model-settings.provider'),
            'model_name' => __('model-settings.model'),
            'model_base_url' => __('model-settings.endpoint'),
            'forget_api_key' => __('model-settings.remove_key'),
        ]);

        if ($apiKey !== null && $apiKey !== '' && (! is_string($apiKey) || strlen($apiKey) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1)) {
            throw ValidationException::withMessages(['model_api_key' => __('model-settings.invalid_key')]);
        }

        $user = $request->user();
        $provider = $settings['model_provider'];
        $baseUrl = $provider === 'custom' ? rtrim($settings['model_base_url'], '/') : null;
        if ($baseUrl !== null) {
            try {
                $endpoints->requestOptions($baseUrl);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['model_base_url' => $exception->getMessage()]);
            }
        }

        $sameDestination = $provider === $user->model_provider && ($provider !== 'custom' || $baseUrl === $user->model_base_url);
        $key = $sameDestination ? $user->model_api_key : null;
        if (is_string($apiKey) && $apiKey !== '') {
            $key = $apiKey;
        }
        if ($request->boolean('forget_api_key') || $provider === 'local') {
            $key = null;
        }
        if ($provider !== 'local' && $key === null && ! $request->boolean('forget_api_key')) {
            throw ValidationException::withMessages(['model_api_key' => __('model-settings.key_required')]);
        }

        $user->update([
            'model_provider' => $provider,
            'model_name' => $provider === 'local' ? null : $settings['model_name'],
            'model_base_url' => $baseUrl,
            'model_api_key' => $key,
        ]);

        return redirect()->route('dashboard', ['panel' => 'settings'])->with('model_settings_saved', true);
    }
}
