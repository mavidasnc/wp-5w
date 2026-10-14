"""
Scarica lo storico di Google Search Console (giorno, pagina, query) in un file JSONL
da importare con `wp 5w gsc-import`.

Serve a recuperare i giorni che Rank Math non ha (la versione free importa solo 90 giorni).
Le credenziali restano sul computer che esegue lo script: sul server arriva solo il file dei dati.

Uso:
    python scripts/gsc-backfill.py --token token-searchconsole.json \
        --site https://maurizio.mavida.com/ --days 180 --out gsc-backfill.jsonl

Requisiti: google-api-python-client, google-auth (token OAuth con scope webmasters.readonly).
"""

import argparse
import json
import sys
from datetime import date, timedelta
from urllib.parse import urlsplit

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

# Massimo di righe per richiesta consentito dall'API.
ROW_LIMIT = 25000


def relative_path(url: str) -> str:
    """URL completo -> percorso relativo (con eventuale query string), come lo salva Rank Math."""
    parts = urlsplit(url)
    path = parts.path or "/"
    return f"{path}?{parts.query}" if parts.query else path


def fetch_day(service, site: str, day: str):
    """Tutte le righe pagina+query di un giorno, con paginazione su startRow."""
    start = 0
    while True:
        response = service.searchanalytics().query(
            siteUrl=site,
            body={
                "startDate": day,
                "endDate": day,
                "dimensions": ["page", "query"],
                "rowLimit": ROW_LIMIT,
                "startRow": start,
            },
        ).execute()
        rows = response.get("rows", [])
        yield from rows
        if len(rows) < ROW_LIMIT:
            return
        start += ROW_LIMIT


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--token", required=True, help="token OAuth autorizzato (JSON)")
    parser.add_argument("--site", required=True, help="proprietà Search Console, es. https://maurizio.mavida.com/")
    parser.add_argument("--days", type=int, default=180, help="giorni da recuperare (fino a ieri)")
    parser.add_argument("--out", required=True, help="file JSONL di destinazione")
    args = parser.parse_args()

    # Il token si rinnova in memoria: il file non viene modificato.
    credentials = Credentials.from_authorized_user_file(args.token)
    if not credentials.valid:
        credentials.refresh(Request())
    service = build("searchconsole", "v1", credentials=credentials, cache_discovery=False)

    end = date.today() - timedelta(days=1)
    total = 0
    with open(args.out, "w", encoding="utf-8", newline="\n") as out:
        for offset in range(args.days - 1, -1, -1):
            day = (end - timedelta(days=offset)).isoformat()
            count = 0
            for row in fetch_day(service, args.site, day):
                page, query = row["keys"]
                out.write(json.dumps({
                    "day": day,
                    "page": relative_path(page),
                    "query": query,
                    "clicks": int(row["clicks"]),
                    "impressions": int(row["impressions"]),
                    "position": round(float(row["position"]), 2),
                }, ensure_ascii=False) + "\n")
                count += 1
            total += count
            print(f"{day}: {count} righe", file=sys.stderr)

    print(f"Totale: {total} righe in {args.out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
