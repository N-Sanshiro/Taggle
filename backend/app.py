# /var/www/html/Taggle/backend/app.py

import os
from typing import Any, Dict

import httpx
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from dotenv import load_dotenv

# ---- env ----
load_dotenv()
HTTP_TIMEOUT = 120
DIFY_API_BASE    = (os.getenv("DIFY_API_BASE", "https://api.dify.ai") or "").rstrip("/")
DIFY_API_KEY     = (os.getenv("DIFY_API_KEY") or "").strip()
DIFY_WORKFLOW_ID = (os.getenv("DIFY_WORKFLOW_ID") or "").strip()
DIFY_INPUT_VAR   = (os.getenv("DIFY_INPUT_VAR") or "tag_image").strip()
DIFY_USER        = (os.getenv("DIFY_USER") or "web-user").strip()

if not DIFY_API_KEY:
    # 起動時にここで落ちるので、まず .env の DIFY_API_KEY を必ず設定しておくこと
    raise RuntimeError("DIFY_API_KEY が未設定です (.env を確認)")

# ---- app ----
app = FastAPI(title="Taggle API")

# CORS（必要に応じて絞ってOK）
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_headers=["*"],
    allow_methods=["*"],
)

# ---------------- Dify 呼び出し -----------------

async def dify_upload_file(client: httpx.AsyncClient, filename: str, content: bytes) -> str:
    url = f"{DIFY_API_BASE}/v1/files/upload"
    headers = {"Authorization": f"Bearer {DIFY_API_KEY}"}
    files = {"file": (filename or "photo.jpg", content, "image/jpeg")}
    data = {"user": DIFY_USER}

    r = await client.post(url, headers=headers, files=files, data=data)
    if r.status_code != 201:
        raise HTTPException(
            r.status_code,
            detail={"message": "upload failed", "text": r.text},
        )
    j = r.json()
    file_id = j.get("id")
    if not file_id:
        raise HTTPException(
            500,
            detail={"message": "upload ok but no file id", "raw": j},
        )
    return file_id


async def dify_run_workflow(client: httpx.AsyncClient, file_id: str, item_name: str):
    url = f"{DIFY_API_BASE}/v1/workflows/run"
    headers = {
        "Authorization": f"Bearer {DIFY_API_KEY}",
        "Content-Type": "application/json",
    }

    inputs: Dict[str, Any] = {
        DIFY_INPUT_VAR: {
            "type": "image",
            "transfer_method": "local_file",
            "upload_file_id": file_id,
        }
    }
    if item_name:
        inputs["item_name"] = item_name

    payload: Dict[str, Any] = {
        "inputs": inputs,
        "response_mode": "blocking",
        "user": DIFY_USER,
    }
    if DIFY_WORKFLOW_ID:
        payload["workflow_id"] = DIFY_WORKFLOW_ID

    r = await client.post(url, headers=headers, json=payload)
    if r.status_code != 200:
        try:
            j = r.json()
        except Exception:
            j = None
        raise HTTPException(
            r.status_code,
            detail={
                "message": "workflow run failed",
                "status": r.status_code,
                "json": j,
                "text": None if j else r.text,
            },
        )
    return r.json()


@app.post("/api/scan_tag_dify")
async def scan_tag_dify(
    file: UploadFile = File(...),
    name: str = Form(""),
):
    """
    フロントから受け取った画像を Dify にアップロードし、
    file_id を使って Workflow を同期実行。結果を返す。
    """
    try:
        content = await file.read()
        if not content:
            raise HTTPException(400, "empty file")

        async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
            file_id = await dify_upload_file(client, file.filename, content)
            data = await dify_run_workflow(client, file_id, name)

        outputs = (data.get("data") or {}).get("outputs") or {}
        summary = (
            outputs.get("result_json")
            or outputs.get("result")
            or outputs.get("text")
            or outputs.get("answer")
            or outputs
            or data
        )
        return {"ok": True, "file_id": file_id, "result": summary, "raw": data}

    except HTTPException as e:
        raise e
    except Exception as e:
        raise HTTPException(
            500,
            detail={"message": "scan_tag_dify failed", "error": str(e)},
        )

# ---------- 類似検索APIはダミーのまま ----------

@app.post("/api/register_cloth_vec")
async def register_cloth_vec(
    file: UploadFile = File(...),
    user_id: str = Form(...),
    name: str = Form(""),
):
    try:
        await file.read()
        return JSONResponse({"ok": True, "message": "vec dummy-registered"})
    except Exception as e:
        return JSONResponse(
            {"ok": False, "error": f"register_cloth_vec failed: {e}"},
            status_code=200,
        )


@app.post("/api/match_cloth_vec")
async def match_cloth_vec(
    file: UploadFile = File(...),
    user_id: str = Form(...),
    threshold: float = Form(0.8),
    top_k: int = Form(10),
):
    try:
        await file.read()
        return JSONResponse(
            {
                "ok": True,
                "matches": [],  # いまは一致なし
            }
        )
    except Exception as e:
        return JSONResponse(
            {"ok": False, "error": f"match_cloth_vec failed: {e}"},
            status_code=200,
        )
