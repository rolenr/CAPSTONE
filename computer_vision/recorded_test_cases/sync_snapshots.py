"""Record all available Zone A-E sectional snapshots in the main database."""

import argparse
import os
import sys
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT))

from computer_vision.full_simulation import (DEFAULT_API, capture_all_zone_baselines,
                                              local_api_server)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--api-url",default=DEFAULT_API)
    parser.add_argument("--vehicle-model",type=Path,default=ROOT / "yolov8n.pt")
    parser.add_argument("--no-preview",action="store_true")
    args = parser.parse_args()
    parsed = urlsplit(args.api_url)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1","localhost") \
            or parsed.path != "/backend/api/endpoints.php" or not parsed.port:
        parser.error("snapshot syncing requires a local parking API URL")
    args.zone = None
    args.scenario_id = ""
    if not args.vehicle_model.is_file():
        parser.error(f"missing vehicle model: {args.vehicle_model}")
    token = os.environ.get("PARKING_EXIT_EVENT_TOKEN","")
    if not token:
        token_file = ROOT / "backend/database/exit_camera_token"
        token = token_file.read_text().strip() if token_file.is_file() else ""
    if len(token) < 32:
        parser.error("camera event token is unavailable")
    with local_api_server(args.api_url):
        capture_all_zone_baselines(args,token)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
