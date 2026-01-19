<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $documents = Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
            ->with('processingRun:id,preset,mode,batch_id')
            ->when($request->search, fn($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->validated === 'yes', fn($q) => $q->whereNotNull('validated_at'))
            ->when($request->validated === 'no', fn($q) => $q->whereNull('validated_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'filters' => $request->only(['search', 'status', 'validated']),
        ]);
    }

    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        return Inertia::render('Documents/Show', [
            'document' => $document->load('processingRun', 'benchmarkResults'),
        ]);
    }

    public function validate(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $document->validate(
            validatedBy: $request->user()->name,
            notes: $request->notes,
        );

        return back()->with('success', 'Document validated.');
    }

    public function unvalidate(Document $document)
    {
        $this->authorize('update', $document);

        $document->update([
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
        ]);

        return back()->with('success', 'Validation removed.');
    }

    public function diff(Document $document): Response
    {
        $this->authorize('view', $document);

        return Inertia::render('Documents/Diff', [
            'document' => $document->only([
                'id', 'name', 'original_text', 'cleaned_text',
                'clean_rate', 'clean_rate_category', 'metrics', 'removals',
            ]),
        ]);
    }
}
