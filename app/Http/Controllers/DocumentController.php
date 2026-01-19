<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocxWriterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): InertiaResponse
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

    public function show(Document $document): InertiaResponse
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

    public function diff(Document $document, \App\Services\Cleaning\DiffService $diffService): InertiaResponse
    {
        $this->authorize('view', $document);

        // Generate diff data
        $diffData = null;
        $diffSummary = null;

        if ($document->original_text && $document->cleaned_text) {
            $diffData = $diffService->generateLineDiff(
                $document->original_text,
                $document->cleaned_text
            );
            $diffSummary = $diffService->getDiffSummary(
                $document->original_text,
                $document->cleaned_text
            );
        }

        return Inertia::render('Documents/Diff', [
            'document' => $document->only([
                'id', 'name', 'original_text', 'cleaned_text',
                'clean_rate', 'clean_rate_category', 'metrics', 'removals',
            ]),
            'diff' => $diffData,
            'summary' => $diffSummary,
        ]);
    }

    /**
     * Download the cleaned document as a .docx file.
     */
    public function download(Document $document, DocxWriterService $writerService): StreamedResponse
    {
        $this->authorize('view', $document);

        if (!$document->cleaned_text) {
            abort(404, 'No cleaned text available for this document');
        }

        // Generate filename
        $baseName = pathinfo($document->name, PATHINFO_FILENAME);
        $filename = $baseName . '_cleaned.docx';

        // Create document bytes
        $content = $writerService->createDocument(
            $document->cleaned_text,
            $document->metadata ?? null
        );

        return response()->streamDownload(
            function () use ($content) {
                echo $content;
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Download the original document as a .txt file.
     */
    public function downloadOriginal(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        if (!$document->original_text) {
            abort(404, 'No original text available for this document');
        }

        // Generate filename
        $baseName = pathinfo($document->name, PATHINFO_FILENAME);
        $filename = $baseName . '_original.txt';

        return response()->streamDownload(
            function () use ($document) {
                echo $document->original_text;
            },
            $filename,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Download the cleaned document as a .txt file.
     */
    public function downloadText(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        if (!$document->cleaned_text) {
            abort(404, 'No cleaned text available for this document');
        }

        // Generate filename
        $baseName = pathinfo($document->name, PATHINFO_FILENAME);
        $filename = $baseName . '_cleaned.txt';

        return response()->streamDownload(
            function () use ($document) {
                echo $document->cleaned_text;
            },
            $filename,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }
}
