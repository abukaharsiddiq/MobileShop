<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Color;

class AttributeController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'name' => 'required|string|max:255'
        ]);

        $slug = Str::slug($request->name);

        if ($request->type === 'category') {
            $item = Category::create([
                'name' => $request->name,
                'slug' => $slug,
                'status' => 1
            ]);
            return response()->json(['id' => $item->id, 'text' => $item->name]);
        }

        if ($request->type === 'brand') {
            $item = Brand::create([
                'brand_name' => $request->name,
                'slug' => $slug,
                'status' => 1
            ]);
            return response()->json(['id' => $item->id, 'text' => $item->brand_name]);
        }

        if ($request->type === 'ram') {
            $item = Ram::create([
                'ram_name' => $request->name,
                'slug' => $slug,
                'status' => 1
            ]);
            return response()->json(['id' => $item->id, 'text' => $item->ram_name]);
        }

        if ($request->type === 'rom') {
            $item = Rom::create([
                'rom_name' => $request->name,
                'slug' => $slug,
                'status' => 1
            ]);
            return response()->json(['id' => $item->id, 'text' => $item->rom_name]);
        }

        if ($request->type === 'color') {
            $item = Color::create([
                'color_name' => $request->name,
                'slug' => $slug,
                'status' => 1
            ]);
            return response()->json(['id' => $item->id, 'text' => $item->color_name]);
        }
    }
}
