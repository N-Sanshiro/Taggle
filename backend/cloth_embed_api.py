# backend/cloth_embed_api.py

from fastapi import FastAPI, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image
from torchvision import models
import torch
import numpy as np
import io, json
import mysql.connector

app = FastAPI()

# CORS (必要なら調整)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/")
def root():
    return {"ok": True, "msg": "cloth_embed_api is running"}

# ====== 1) ResNet18 を特徴抽出器として準備 ======

# 学習済み重みの指定（torch 2系）
from torchvision.models import ResNet18_Weights
weights = ResNet18_Weights.DEFAULT

model = models.resnet18(weights=weights)
model.fc = torch.nn.Identity()  # 最終分類層を Identity に差し替え → 512次元
model.eval()

preprocess = weights.transforms()

def image_to_vec(image_bytes: bytes) -> np.ndarray:
    """画像バイト列 → 512次元ベクトル（L2正規化済み）"""
    img = Image.open(io.BytesIO(image_bytes)).convert("RGB")
    x = preprocess(img).unsqueeze(0)  # (1,3,224,224)
    with torch.no_grad():
        v = model(x).squeeze(0).numpy()  # (512,)
    # L2正規化（cos類似度が安定）
    v = v / (np.linalg.norm(v) + 1e-8)
    return v

def cosine_sim(a: np.ndarray, b: np.ndarray) -> float:
    """cos類似度（-1〜1, 1に近いほど似てる）"""
    return float(np.dot(a, b))

# ====== 2) DB 接続周り ======

def get_db():
    # ★ ここは自分の XAMPP MySQL 設定に合わせて変更
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="taggle",
    )

# ====== 3) 服登録 API ======

@app.post("/api/register_cloth_vec")
async def register_cloth_vec(
    user_id: int = Form(...),
    name: str = Form(...),
    file: UploadFile = File(...),
):
    """
    ・服画像を登録
    ・ResNet で 512次元ベクトルを計算
    ・cloth テーブルの embed_json に保存
    """
    image_bytes = await file.read()
    vec = image_to_vec(image_bytes)
    vec_list = vec.astype(np.float32).tolist()
    vec_json = json.dumps(vec_list)

    db = get_db()
    cur = db.cursor()

    # cloth_image にも画像を保存する想定
    sql = """
      INSERT INTO clothes (id_user, cloth_image, name_cloth, embed_json)
      VALUES (%s, %s, %s, %s)
    """
    cur.execute(sql, (user_id, image_bytes, name, vec_json))
    db.commit()

    new_id = cur.lastrowid

    cur.close()
    db.close()

    return {"ok": True, "id_cloth": int(new_id)}

# ====== 4) 類似服検索 API ======

@app.post("/api/match_cloth_vec")
async def match_cloth_vec(
    user_id: int = Form(...),
    file: UploadFile = File(...),
    threshold: float = Form(0.8),   # 類似度の閾値（0〜1）
    top_k: int = Form(5),
):
    """
    ・クエリ画像をベクトル化
    ・同じユーザの cloth から embed_json を全件読み込み
    ・cos類似度を計算して上位を返す
    """
    image_bytes = await file.read()
    q_vec = image_to_vec(image_bytes)

    db = get_db()
    cur = db.cursor(dictionary=True)

    cur.execute("""
        SELECT id_cloth, name_cloth, embed_json, cloth_image
        FROM clothes
        WHERE id_user = %s AND embed_json IS NOT NULL
    """, (user_id,))

    rows = cur.fetchall()
    cur.close()
    db.close()

    scored = []
    for row in rows:
        try:
            vec_list = json.loads(row["embed_json"])
            v = np.array(vec_list, dtype=np.float32)
            v = v / (np.linalg.norm(v) + 1e-8)
            score = cosine_sim(q_vec, v)
        except Exception:
            continue

        scored.append({
            "id_cloth": int(row["id_cloth"]),
            "name_cloth": row["name_cloth"],
            "score": score,
            # サムネ用にそのまま返す / もしくは別APIにする
            "cloth_image": "data:image/jpeg;base64," + 
                           row["cloth_image"].hex(),  # BLOB→hex; base64にしたければPHP側でやってもOK
        })

    # スコア降順にソート
    scored.sort(key=lambda x: x["score"], reverse=True)

    # 閾値を超えるものだけ
    filtered = [s for s in scored if s["score"] >= threshold][:top_k]

    return {
        "ok": True,
        "matches": filtered,
        "query_top_score": float(filtered[0]["score"]) if filtered else None
    }
