import EtlCard from '../components/EtlCard';
import GscImportCard from '../components/GscImportCard';
import RankMathCard from '../components/RankMathCard';
import UpdatesCard from '../components/UpdatesCard';

/**
 * Scheda «Aggiornamenti»: tutti i pulsanti che aggiornano i dati (con la spiegazione di cosa importano e da dove)
 * e, in fondo, la versione del plugin.
 */
export default function UpdatesPage() {
	return (
		<div className="wp5w-updates">
			<EtlCard />
			<GscImportCard />
			<RankMathCard />
			<UpdatesCard />
		</div>
	);
}
