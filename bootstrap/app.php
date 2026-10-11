<?php

use App\Http\Middleware\Administrator;
use App\Http\Middleware\CaptureAccess;
use App\Support\ApiProblem;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['csv_delimiter', 'multiselect_rules.*.delimiter', 'multiselect_rules.*.selected_values.*', 'multiselect_rules.*.unselected_values.*']);
        $middleware->alias(['admin' => Administrator::class, 'capture' => CaptureAccess::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof ApiProblem) {
                return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => (object) $e->errors], $e->status);
            }
            if ($e instanceof ValidationException) {
                return response()->json(['message' => 'Revise los parámetros enviados.', 'code' => 'validation_failed', 'errors' => $e->errors()], 422);
            }
            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'Autenticación requerida.', 'code' => 'unauthenticated', 'errors' => (object) []], 401);
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = match ($status) {
                404 => 'not_found', 403 => 'forbidden', 429 => 'rate_limited', 413 => 'file_too_large', default => 'request_failed'
            };

            return response()->json(['message' => $status >= 500 ? 'No se pudo completar la operación.' : 'La solicitud no está permitida o el recurso no existe.', 'code' => $code, 'errors' => (object) []], $status);
        });
    })->create();
