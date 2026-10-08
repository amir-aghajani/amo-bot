<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Base for JSON endpoints. An action is load → service → present: the error handler answers what goes wrong in the
 * one error shape {"message": string, "errors"?: {field: [messages]}} — a refusal (DomainRuleException, a
 * ValidationException among them) with its status and fields, a row that is not there with a 404 — so an action
 * catches only what it words its own way.
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
     * Bytes a panel shows or saves — a receipt, a sticker — with their length and how long a browser may keep them
     * (`$cache`, a Cache-Control value). A type the panels show (INLINE) goes as itself, to be shown; anything else,
     * whatever it is — a document a customer sent as a receipt, an SVG —, as `application/octet-stream` to be saved,
     * never rendered. Either way the answer's policy lets nothing run on the panels' origin
     * (SecurityHeadersMiddleware). `$filename` names them, in any script.
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
     * The row the route names (`{id}`) with `$with` loaded — looked up in the current shop (the model's BelongsToBot
     * scope), so another shop's row is as missing as a deleted one: the error handler's 404.
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
        return $model::query()->with($with)->findOrFail(Input::integerOf($args['id'] ?? null) ?? 0);
    }

    /**
     * The on/off a request sets under `$field` — refused (a 422 on the field) when it is missing or anything but a
     * boolean (true/false, 1/0), never read as "off".
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
            throw new ValidationException(['ids' => ['لیست شناسه‌ها خالی است.']]);
        }

        return array_values(array_map(intval(...), $ids));
    }
}
