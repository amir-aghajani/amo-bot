<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Base for JSON endpoints. An action is load → service → present; ErrorHandler answers refusals (DomainRuleException)
 * and missing rows (404) in the one error shape, so an action catches only what it phrases differently.
 */
abstract class ApiController
{
    /** What a panel shows as it is: pictures, and a sticker's video. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/webm'];

    /** The longest file name an answer carries: enough for any a person gives a file. */
    private const FILENAME_MAX = 120;

    protected function json(Response $response, mixed $data, int $status = 200): Response
    {
        return Json::respond($response, $data, $status);
    }

    protected function noContent(Response $response): Response
    {
        return $response->withStatus(204);
    }

    /**
     * A file answer (a receipt, a sticker) with its length and `$cache` (a Cache-Control value). A picture or a sticker's
     * video goes as itself; anything else — a document a customer sent, an SVG — as an `application/octet-stream`
     * attachment, so it is saved, never rendered on the panels' origin.
     */
    protected function bytes(Response $response, string $body, string $mime, string $cache, ?string $filename = null): Response
    {
        $inline = in_array(strtolower($mime), self::INLINE, true);

        $response->getBody()->write($body);
        $response = $response
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', $cache);

        if ($inline && $filename === null) {
            return $response;
        }

        $disposition = $inline ? 'inline' : 'attachment';
        if ($filename === null) {
            return $response->withHeader('Content-Disposition', $disposition);
        }

        // The real name (RFC 5987) for today's browsers, a plain-ASCII one for the rest — nothing that can break the header.
        $filename = mb_substr($filename, 0, self::FILENAME_MAX);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);

        return $response->withHeader('Content-Disposition', sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $disposition, $ascii, rawurlencode($filename)));
    }

    /**
     * The row the route's `{id}` names, with `$with` loaded. The BelongsToBot scope makes another shop's row as missing
     * as a deleted one: a 404.
     *
     * @template TModel of Model
     * @param class-string<TModel> $model
     * @param array<string, string> $args The route's arguments
     * @param list<string> $with
     * @return TModel
     * @throws ModelNotFoundException
     */
    protected function load(string $model, array $args, array $with = []): Model
    {
        return $model::query()->with($with)->findOrFail($this->routeId($args));
    }

    /**
     * The route argument `$name` as a row id; 0 (no row) for anything else.
     *
     * @param array<string, string> $args The route's arguments
     */
    protected function routeId(array $args, string $name = 'id'): int
    {
        return Input::integerOf($args[$name] ?? null) ?? 0;
    }

    /** A paged list's request — its page, search, filters and order — read off the query string. */
    protected function pageRequest(Request $request): PageRequest
    {
        return PageRequest::fromQuery($request->getQueryParams());
    }

    /** The file a multipart form uploaded under `$field`; null for none, or a JSON body. */
    protected function uploadedFile(Request $request, string $field = 'file'): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()[$field] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * A switch the request sets under `$field`: a 422 when it is missing or not a boolean — never read as "off".
     *
     * @throws ValidationException
     */
    protected function switch(Request $request, string $field): bool
    {
        $value = $this->input($request)[$field] ?? null;
        if (!Input::isBoolean($value)) {
            throw ValidationException::on($field, Validation::NOT_A_SWITCH);
        }

        return Input::truthy($value);
    }

    /** @return array<string, mixed> */
    protected function input(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * The `ids` list of a reorder request, as integers.
     *
     * @return list<int>
     * @throws ValidationException when it is missing or empty
     */
    protected function reorderIds(Request $request): array
    {
        $ids = $this->input($request)['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw ValidationException::on('ids', 'لیست شناسه‌ها خالی است.');
        }

        return array_values(array_map(intval(...), $ids));
    }
}
