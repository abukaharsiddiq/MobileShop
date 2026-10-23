<?php

if (!function_exists('changeStatusHelper')) {
    function changeStatusHelper($model,$request){
        if ($model) {
            $model->status = $request->status;
            $model->save();
            return response()->json(['success' => true, 'message' => 'Status updated successfully.']);
        }

        return response()->json(['success' => false, 'message' => 'Failed to update status.']);
    }
}

if (!function_exists('errorMessage')) {
    // User ke ki error dekhabe. Code er nijer `throw new \Exception('...')` er message dekhano jay,
    // kintu database/system error er detail shudhu log e jabe (APP_DEBUG=false hole).
    function errorMessage(\Throwable $e){
        if (get_class($e) === \Exception::class) {
            return $e->getMessage();
        }

        report($e);

        return config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again.';
    }
}
