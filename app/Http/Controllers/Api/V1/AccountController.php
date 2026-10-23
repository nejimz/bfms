<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        return AccountResource::collection(
            Account::query()->orderBy('code')->get()
        );
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $account = Account::query()->create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return new AccountResource($account);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return new AccountResource($account);
    }
}
