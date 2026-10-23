<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Convert the given exception to an array.
     *
     * @param  \Throwable  $e
     * @return array
     */
    protected function convertExceptionToArray(Throwable $e) : array
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $this->getMessage($e)
        ];
    }

    private function getMessage (Throwable $e) : string {
        if ($this->isHttpException($e)) {
            if ($e->getPrevious() instanceof TokenMismatchException){
                return __('Your Session has expired. Please login again to continue.');
            } else {
                return $e->getMessage();
            }
        } else {
            return __('Whoops! An error occurred. Please try again later.');
        }
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof TokenMismatchException) {
            $message = __('Your admin session token expired. Refresh the page and try the action again.');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $message], 419);
            }

            if ($request->is('admin/avdb-import*') && Auth::check() && Auth::user()?->is_admin) {
                return redirect()
                    ->route('admin.avdb-import.index')
                    ->withErrors($message);
            }
        }

        if ($e instanceof ModelNotFoundException) {
            $model = (new \ReflectionClass($e->getModel()))->getShortName();
            abort(404, __('The route :route could not be found.', ['route' => request()->path()]));
        }

        return parent::render($request, $e);
    }
}
