<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /**
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main', int $status = 200): Response
    {
        return new Response(View::render($view, $data, $layout), $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    /**
     * @param array<mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash('alert', ['type' => $type, 'message' => $message]);
    }

    /**
     * Validate request input. On failure the errors and the submitted values
     * (never passwords) are flashed and the user is sent back to $backTo.
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @param array<int,string> $sensitive field names that must not be flashed back
     * @return array<string,mixed>|Response validated data, or a redirect response on failure
     */
    protected function validate(Request $request, array $rules, string $backTo, array $labels = [], array $sensitive = []): array|Response
    {
        $validator = Validator::make($request->allPost(), $rules, $labels);
        if ($validator->passes()) {
            return $validator->validated();
        }

        return $this->redirectWithErrors($request, $validator->errors(), $backTo, $sensitive);
    }

    /**
     * Send the user back with field errors and their submitted values (never passwords or the CSRF token).
     *
     * @param array<string,array<int,string>> $errors
     * @param array<int,string> $sensitive field names that must not be flashed back
     */
    protected function redirectWithErrors(Request $request, array $errors, string $backTo, array $sensitive = []): Response
    {
        $old = $request->allPost();
        foreach (array_merge($sensitive, ['_csrf']) as $field) {
            unset($old[$field]);
        }
        foreach ($old as $field => $value) {
            if (stripos((string) $field, 'password') !== false) {
                unset($old[$field]);
            }
        }

        Session::flash('errors', $errors);
        Session::flash('old', $old);
        return Response::redirect($backTo);
    }
}
