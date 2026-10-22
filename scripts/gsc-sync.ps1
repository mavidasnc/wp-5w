<#
.SYNOPSIS
    Aggiorna sul blog i dati di Search Console degli ultimi giorni.

.DESCRIPTION
    1. scarica da Search Console gli ultimi N giorni (scripts/gsc-backfill.py);
    2. copia il file JSONL sul server (alias SSH "mavida-blog");
    3. lo importa con "wp 5w gsc-import".

    L'import sostituisce giorno per giorno: si può rilanciare quando si vuole. Gli ultimi giorni
    cambiano ancora per un po' dopo la prima pubblicazione di Google, per questo si riscarica
    sempre una finestra di 7 giorni (non solo i giorni mancanti). Search Console ha un ritardo
    di circa 2 giorni: i giorni senza dati restano vuoti e non cancellano nulla sul blog.

    Il segreto Google resta su questo computer: sul server arriva solo il file dei dati.

.EXAMPLE
    # A mano
    .\scripts\gsc-sync.ps1

.EXAMPLE
    # Recupero più lungo dopo un periodo di computer spento
    .\scripts\gsc-sync.ps1 -Days 30

.EXAMPLE
    # Pianifica ogni giorno alle 9:00 (una tantum, poi vive in Utilità di pianificazione)
    .\scripts\gsc-sync.ps1 -Register -At 09:00
#>
[CmdletBinding()]
param(
	# Giorni da scaricare, fino a ieri.
	[int]$Days = 7,

	# Crea (o aggiorna) l'attività pianificata di Windows e termina senza sincronizzare.
	[switch]$Register,

	# Orario dell'attività pianificata (formato HH:mm).
	[string]$At = '09:00',

	[string]$Token = (Join-Path $HOME 'Claude\credenzials\google\maurizio.pelizzone@gmail.com\token-searchconsole.json'),
	[string]$Site = 'https://maurizio.mavida.com/',
	[string]$SshHost = 'mavida-blog',
	[string]$RemoteSite = '~/domains/mavida.com/public_html/maurizio',
	[string]$WpUrl = 'https://maurizio.mavida.com'
)

$ErrorActionPreference = 'Stop'

# Cartelle di lavoro: il file dei dati e il log stanno in .wp-env-data (ignorata da git).
$Root    = Split-Path -Parent $PSScriptRoot
$DataDir = Join-Path $Root '.wp-env-data'
$Local   = Join-Path $DataDir 'gsc-sync.jsonl'
$Log     = Join-Path $DataDir 'gsc-sync.log'
$Remote  = 'backups/gsc-sync.jsonl'

# Modalità registrazione: crea l'attività giornaliera e esce.
if ($Register) {
	$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
		-Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f $PSCommandPath)
	$trigger = New-ScheduledTaskTrigger -Daily -At $At
	# StartWhenAvailable: se alle 9:00 il computer è spento, parte appena si riaccende.
	$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RunOnlyIfNetworkAvailable `
		-ExecutionTimeLimit (New-TimeSpan -Minutes 30)
	Register-ScheduledTask -TaskName 'wp-5w GSC sync' -Action $action -Trigger $trigger `
		-Settings $settings -Description 'Importa su maurizio.mavida.com i dati di Search Console' -Force | Out-Null
	Write-Host "Attività 'wp-5w GSC sync' registrata ogni giorno alle $At (solo con l'utente collegato)."
	return
}

# Esegue un comando esterno unendo stdout e stderr, così finiscono entrambi nel log (la trascrizione
# di Windows PowerShell 5.1 non cattura l'output dei programmi nativi).
function Invoke-Logged([string]$CommandLine) {
	& cmd.exe /c "$CommandLine 2>&1" | Out-Host
}

New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
Start-Transcript -Path $Log -Append | Out-Null

try {
	Write-Host ("--- {0:yyyy-MM-dd HH:mm:ss} ---" -f (Get-Date))

	# 1. Download da Search Console (il token si rinnova in memoria).
	$env:PYTHONIOENCODING = 'utf-8'
	Invoke-Logged ('python "{0}" --token "{1}" --site {2} --days {3} --out "{4}"' -f (Join-Path $PSScriptRoot 'gsc-backfill.py'), $Token, $Site, $Days, $Local)
	if ($LASTEXITCODE -ne 0) { throw "Download da Search Console fallito (codice $LASTEXITCODE). Se è invalid_grant il token va rigenerato." }

	# Senza righe (es. API non raggiungibile con risposta vuota) non c'è nulla da importare.
	if ((Get-Item $Local).Length -eq 0) { throw 'Search Console non ha restituito righe: import saltato.' }

	# 2. Copia sul server.
	Invoke-Logged ('scp -q "{0}" {1}:{2}' -f $Local, $SshHost, $Remote)
	if ($LASTEXITCODE -ne 0) { throw "Copia sul server fallita (codice $LASTEXITCODE)." }

	# 3. Import sul blog (rimpiazza i giorni presenti nel file).
	Invoke-Logged ('ssh {0} "cd {1} && wp --url={2} 5w gsc-import ~/{3}"' -f $SshHost, $RemoteSite, $WpUrl, $Remote)
	if ($LASTEXITCODE -ne 0) { throw "Import sul blog fallito (codice $LASTEXITCODE)." }

	Write-Host 'Completato.'
}
catch {
	Write-Host "ERRORE: $($_.Exception.Message)"
	exit 1
}
finally {
	Stop-Transcript | Out-Null
}
