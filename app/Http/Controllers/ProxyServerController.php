<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProxyServerRequest;
use App\Http\Requests\UpdateProxyServerRequest;
use App\Models\ProxyServer;
use App\Services\EdgeProxyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProxyServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Panel/ProxyServers/Index', [
            'servers' => ProxyServer::query()
                ->orderBy('id')
                ->get()
                ->map(fn (ProxyServer $server) => $server->toPanelArray())
                ->values(),
        ]);
    }

    public function store(StoreProxyServerRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $host = trim((string) $data['host']);

        ProxyServer::query()->create([
            'host' => $host,
            'port' => (int) ($data['port'] ?? 22),
            'username' => $data['username'] ?? 'root',
            'password' => filled($data['password'] ?? null) ? $data['password'] : null,
            'label' => $data['label'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('proxy-servers.index')
            ->with('success', "Проксі {$host} додано.");
    }

    public function update(UpdateProxyServerRequest $request, ProxyServer $proxyServer): RedirectResponse
    {
        $data = $request->validated();
        $proxyServer->host = trim((string) $data['host']);
        $proxyServer->port = (int) ($data['port'] ?? 22);
        $proxyServer->username = $data['username'] ?? 'root';
        $proxyServer->label = $data['label'] ?? null;
        if ($request->has('is_active')) {
            $proxyServer->is_active = $request->boolean('is_active');
        }
        if (filled($data['password'] ?? null)) {
            $proxyServer->password = $data['password'];
        }
        $proxyServer->save();

        return redirect()
            ->route('proxy-servers.index')
            ->with('success', 'Проксі оновлено.');
    }

    public function destroy(ProxyServer $proxyServer): RedirectResponse
    {
        $label = $proxyServer->displayLabel();
        $proxyServer->delete();

        return redirect()
            ->route('proxy-servers.index')
            ->with('success', "{$label} видалено. Офери на ньому лишаються, поки їх не перекинеш назад на CF.");
    }

    public function check(ProxyServer $proxyServer, EdgeProxyService $edge): RedirectResponse
    {
        $result = $edge->checkHealth($proxyServer);
        $ok = (bool) ($result['ok'] ?? false);

        $proxyServer->forceFill([
            'health' => [
                'status' => $ok ? 'ok' : 'down',
                'checked_at' => now()->toIso8601String(),
                'message' => $result['message'] ?? null,
            ],
        ])->save();

        return redirect()
            ->route('proxy-servers.index')
            ->with(
                $ok ? 'success' : 'warning',
                $proxyServer->displayLabel().': '.($result['message'] ?? 'немає відповіді'),
            );
    }
}
