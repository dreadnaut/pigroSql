# PigroSql

PigroSql è una libreria che riduce lo sforzo di usare un database in una piccola
applicazione PHP.

Internamente si basa sulle funzioni PDO del PHP, che possono essere utilizzate
per operazioni più complesse.

Richiede PHP 8.1 o superiore.

## Istruzioni per l'uso

### Connessione al database

```php
require 'PigroSql.php';

# MySQL
$db = new Pigro\Database("mysql:host=...;dbname=...", $username, $password);

# MySQL su Altervista
$db = Pigro\Database::mysqlAltervista($nick);

# SQLite
$db = new Pigro\Database('sqlite:file_del_database.sqlite');
```

### Eseguire una query

Il metodo principale per eseguire una query è `esegui()`, che restituisce un
oggetto `PDOStatement`. In caso di errore, emette un'eccezione `PDOException`.

```php
$risultato = $db->esegui('SELECT titolo, url FROM articoli');
```

```php
$articolo = $db->esegui('SELECT titolo, url FROM articoli')->fetch();

echo "<h1>{$articolo['titolo']}</h1>";
echo "<a href="{$articolo['url']}">Leggi...</a>";
```

Se la query richiede parametri, la funzione `esegui` può gestire anche questi:

```php
$articoli = $db->esegui('SELECT * FROM articoli WHERE categoria = ?', $_POST['categoria']);
```

PigroSql costruirà un [prepared statement](https://www.php.net/manual/pdo.prepared-statements.php) ed assegnerà i parametri in modo sicuro. Quando ci sono più parametri, suggerisco di utilizzare segnaposto espliciti, più leggibili rispetto a `?`:

```php
$db->esegui(
  'UPDATE articoli set titolo = :nuovo_titolo WHERE titolo = :titolo',
  [
    'titolo' => $titoloCorrente,
    'nuovo_titolo' => $_POST['titolo'],
  ]
);
```

### Raccogliere risultati

PigroSql offre metodi 'scorciatoia' per alcune operazioni comuni sui risultati.

#### Un singolo elemento

Restituisce solo il primo risultato della query. Di solito usato con query
che includono `LIMIT 1`.

```php
$piùRecente = $db->primo('SELECT * FROM articoli ORDER BY creato_il DESC LIMIT 1');

echo "<h1>{$piùRecente['titolo']}</h1>";
echo "<a href="{$piùRecente['url']}">Leggi...</a>";
```

#### Tutti i risultati

Restituisce un array con tutti i risultati della query.

```php
$questAnno = $db->tutti('SELECT * FROM articoli WHERE YEAR(creato_il) = ?', date('Y'));
```

#### Uno risultato alla volta

Se la query restituisce migliaia di risultati, metterli tutti in un array
potrebbe non essere una buona idea. Con `unoAllaVolta()` si aggira questo
problema.

```php
foreach ($db->unoAllaVolta('SELECT * FROM visite_uniche') as $visita) {
  fputcsv($archivioCsv, $visita);
}
```

#### Una colonna sola

Nei casi in cui si voglia estrarre una singola colonna dai risultati, questo metodo
la restituisce in un comodo array.

```php
$anni = $db->colonna('SELECT DISTINCT YEAR(creato_il) FROM articoli');
```

#### Un singolo valore

Per query che restituiscono un singolo dato, esiste `valore()`, che ritorna il
primo campo del primo risultato della query. Questa è la funzione perfetta per
contare risultati.

```php
$numeroArticoli = $db->valore('SELECT COUNT(*) FROM articoli WHERE autore = ?', $nomeUtente);
```

### Scegliere il formato dei risultati

Senza altre indicazioni, PigroSql restituisce il risultato delle query sotto
forma di array associativo. Si può modificare il default al momento
dell'inizializzazione:

```php
$db = new Pigro\Database(
  'sqlite:file_del_database.sqlite',
  formatoRisultati: PDO::FETCH_OBJ
);
```

Oppure per una singola query:

```php
$risultato = $db->esegui('SELECT titolo, url FROM articoli');
$risultato->setFetchMode(PDO::FETCH_OBJ);
$articolo = $risultato->fetch();
```

### Accesso semplificato ad una tabella

PigroSQL può creare automaticamente certe query che lavorano su una sola
tabella, come estrarre risultati, inserire una nuova riga, aggiornarne o
rimuoverne una esistente.

```php
$articoli = $db->tabella('articoli');

$articoli->tutti / uno / conta / inserisci / sostituisci / aggiorna / elimina
```
...


### Raggruppare risultati

`indicizza()` restituisce un array associativo, utilizzando come chiavi il
valore del campo scelto. Questo dovrebbe essere *unico*: se più elementi hanno
lo stesso valore, solo uno sarà incluso nell'array risultante.

```php
$risultati = $db->indicizza($articoli, 'url');
```

Anche `raggruppa()` restituisce un array associativo, ma ad ogni chiave è
associato un array con *tutti* i risultati corrispondenti a quel valore.

```php
$risultati = $db->tutti('SELECT *, YEAR(creato_il) AS anno FROM articoli');
$articoliPerAnno = $db->raggruppa($risultati, 'anno');
```


## Sviluppo

...


## Informazioni

...
