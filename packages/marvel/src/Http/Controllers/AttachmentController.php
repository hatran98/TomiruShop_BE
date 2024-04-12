<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Marvel\Database\Models\Attachment;
use Marvel\Database\Repositories\AttachmentRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AttachmentRequest;
use Prettus\Validator\Exceptions\ValidatorException;


class AttachmentController extends CoreController
{
    public $repository;

    public function __construct(AttachmentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Attachment[]
     */
    public function index(Request $request)
    {
        return $this->repository->paginate();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param AttachmentRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(AttachmentRequest $request)
    {
        $urls = [];

        foreach ($request->attachment as $media) {
            // Lưu tệp lên S3 và lấy URL
            $originalFileName = 'media/' . uniqid() . '.' . $media->getClientOriginalExtension();
            Storage::disk('another_bucket')->putFileAs('', $media, $originalFileName, 'public');
            $originalUrl = Storage::disk('another_bucket')->url($originalFileName);

            // Tạo attachment
            $attachment = new Attachment;
            $attachment->save();

            // Tạo media từ URL và thêm vào attachment
            $mediaItem = $attachment->addMediaFromUrl($originalUrl)->toMediaCollection();

            // Kiểm tra xem tệp có phải là hình ảnh hay không
            if (strpos($mediaItem->mime_type, 'image/') === 0) {
                // Tạo thumbnail từ hình ảnh gốc
                $thumbnail = Image::make($media)->fit(348, 232)->encode();
                // Lưu thumbnail lên S3 và lấy URL
                $thumbnailFileName = 'media/thumbnails/' . $attachment->id . '.' . $media->getClientOriginalExtension();
                Storage::disk('another_bucket')->put($thumbnailFileName, $thumbnail->__toString(), 'public');
                $thumbnailUrl = Storage::disk('another_bucket')->url($thumbnailFileName);
            } else {
                // Nếu không phải là hình ảnh, không tạo thumbnail
                $thumbnailUrl = null;
            }

            // Tạo mảng chứa URL và ID của tệp
            $converted_url = [
                'original' => $originalUrl,
                'thumbnail' => $thumbnailUrl,
                'id' => $attachment->id
            ];


            // Thêm vào mảng URLs
            $urls[] = $converted_url;
        }

        return $urls; // Trả về mảng chứa các URL của các tệp đã lưu
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param AttachmentRequest $request
     * @param int $id
     * @return bool
     */
    public function update(AttachmentRequest $request, $id)
    {
        return false;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
