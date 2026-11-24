<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWhiteLandingRequest;
use App\Models\WhiteLanding;
use App\Services\WhiteLandingStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WhiteLandingController extends Controller
{
    public function index(Request $request, WhiteLandingStore $whites): Response
    {
        return Inertia::render('Panel/Landings/Index', [
            'landings' => $whites->libraryFor($request->user()),
        ]);
    }

    public function store(StoreWhiteLandingRequest $request, WhiteLandingStore $whites): RedirectResponse
    {
        try {
            $landing = $whites->store(
                $request->user(),
                $request->file('zip'),
                $request->string('name')->toString() ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('landings.index')
                ->withErrors(['zip' => $e->getMessage()]);
        }

        return redirect()
            ->route('landings.index')
            ->with('success', "Вайт «{$landing->name}» додано. Його можна обрати на кроці Інфра → Кло.");
    }

    public function destroy(Request $request, WhiteLanding $landing, WhiteLandingStore $whites): RedirectResponse
    {
        $this->owned($request, $landing);

        try {
            $name = $landing->name;
            $whites->delete($landing);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('landings.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('landings.index')
            ->with('success', "Вайт «{$name}» видалено.");
    }

    public function preview(Request $request, WhiteLanding $landing, WhiteLandingStore $whites, ?string $path = null): BinaryFileResponse
    {
        $this->owned($request, $landing);

        return $whites->preview($landing, trim((string) $path, '/'));
    }

    private function owned(Request $request, WhiteLanding $landing): void
    {
        if ((int) $landing->user_id !== (int) $request->user()->id) {
            abort(404);
        }
    }
}
