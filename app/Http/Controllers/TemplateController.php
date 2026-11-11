<?php

namespace App\Http\Controllers;

use App\Services\TemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(TemplateCatalog $catalog): Response
    {
        return Inertia::render('Panel/Templates/Index', [
            'templates' => $catalog->forLibrary(),
        ]);
    }

    public function updateAvailability(Request $request, string $template, TemplateCatalog $catalog): RedirectResponse
    {
        if (! in_array($template, $catalog->ids(), true)) {
            abort(404);
        }

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = $request->boolean('enabled');
        $catalog->setEnabled($template, $enabled);
        $label = $catalog->label($template);

        return redirect()
            ->route('templates.index')
            ->with(
                'success',
                $enabled
                    ? "Шаблон «{$label}» знову можна обирати для нових оферів."
                    : "Шаблон «{$label}» вимкнено — його більше не можна обрати для нових оферів.",
            );
    }
}
