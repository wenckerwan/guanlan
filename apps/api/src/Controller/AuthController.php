<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\UserResource;
use App\Service\AuthService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\Validator;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AuthController
{
    public function __construct(
        private AuthService $service,
        private RequestInterface $request
    ) {
    }

    public function sendCode(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('email', '邮箱')->email('email', '邮箱');
        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $serverParams = $this->request->getServerParams();
        $ip = (string) ($this->request->getHeaderLine('X-Real-IP') ?: $this->request->getHeaderLine('X-Forwarded-For'));
        if ($ip === '') {
            $ip = (string) ($serverParams['remote_addr'] ?? '');
        } elseif (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $result = $this->service->sendRegisterCode($validator->string('email'), $ip);
        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], 429);
        }

        return ApiResponse::data(['ok' => true]);
    }

    public function register(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('email', '邮箱');
        $validator->email('email', '邮箱')
            ->required('password', '密码')
            ->min('password', 6, '密码')
            ->max('email', 191, '邮箱');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $code = $this->request->input('code');
        $result = $this->service->register(
            $validator->string('email'),
            (string) $this->request->input('password', ''),
            $validator->string('displayName'),
            $code !== null ? (string) $code : null
        );

        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], 409);
        }

        $user = $result['user'];

        try {
            $token = Auth::issue($user, $this->request->getHeaderLine('User-Agent'));
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() !== 401) throw $exception;
            return ApiResponse::message($exception->getMessage(), 401);
        }

        return ApiResponse::data([
            'token' => $token,
            'user' => UserResource::make($user),
        ], 201);
    }

    public function login(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('email', '邮箱')
            ->required('password', '密码');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $user = $this->service->login(
            $validator->string('email'),
            (string) $this->request->input('password', '')
        );

        if (! $user) {
            return ApiResponse::message('邮箱或密码不正确', 401);
        }

        try {
            $token = Auth::issue($user, $this->request->getHeaderLine('User-Agent'));
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() !== 401) throw $exception;
            return ApiResponse::message($exception->getMessage(), 401);
        }

        return ApiResponse::data([
            'token' => $token,
            'user' => UserResource::make($user),
        ]);
    }

    public function me(): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录或登录已失效', 401);
        }

        return ApiResponse::data(['user' => UserResource::make($user)]);
    }

    public function logout(): ResponseInterface
    {
        Auth::revoke(Auth::tokenFrom($this->request));

        return ApiResponse::data(['ok' => true]);
    }
}
