from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.config import get_settings
from app.routers.api import router

settings = get_settings()
app = FastAPI(title="Wi-Fi Connected Patient-End Fall Detection API", version="0.1.0", description="Local prototype; all synthetic records are clearly marked.")
app.add_middleware(CORSMiddleware, allow_origins=[x.strip() for x in settings.cors_origins.split(",")], allow_credentials=True, allow_methods=["*"], allow_headers=["*"])
app.include_router(router, prefix="/api/v1")

@app.get("/health")
def health(): return {"status":"ok","simulation_mode":True,"message":"No physical ESP32 or camera connection is enabled"}
