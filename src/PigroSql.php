<?php

namespace Pigro;

class Database
{
  public static function mysqlAltervista(
    string $nick,
    int $formatoRisultati = \PDO::FETCH_ASSOC,
  ) : self
  {
    return new self(
      "mysql:host=localhost;dbname=my_{$nick}",
      $nick,
      formatoRisultati: $formatoRisultati
    );
  }

  public readonly \PDO $pdo;

  public function __construct(
    string $connessione,
    string $utente = null,
    string $password = null,
    int $formatoRisultati = \PDO::FETCH_ASSOC,
  ) {
    $this->pdo = new \PDO($connessione, $utente, $password);
    $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, $formatoRisultati);
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
  }

  public function esegui(string $query, mixed $parametri = [], $classe = null) : \PDOStatement
  {
    if (empty($parametri)) {
      return $this->pdo->query($query);
    }

    [ $query, $parametri ] = $this->espandiParametri($query, (array) $parametri);

    $statement = $this->pdo->prepare($query);
    $statement->execute($parametri);
    if ($classe) {
      $statement->setFetchMode(\PDO::FETCH_CLASS, $classe);
    }
    return $statement;
  }

  public function transazione(\Closure $codice)
  {
    $this->pdo->beginTransaction();
    try {
      $codice($this);
      return $this->pdo->commit();
    } catch (\Exception $ex) {
      $this->pdo->rollback();
      throw $ex;
    }
  }

  public function primo(...$parametriEsegui) : mixed
  {
    $statement = $this->esegui(...$parametriEsegui);
    return $statement->fetch() ?: null;
  }

  public function tutti(...$parametriEsegui) : array
  {
    $statement = $this->esegui(...$parametriEsegui);
    return $statement->fetchAll();
  }

  public function unoAllaVolta(...$parametriEsegui) : \Generator
  {
    $statement = $this->esegui(...$parametriEsegui);
    while ($risultato = $statement->fetch()) {
      yield $risultato;
    }
  }

  public function colonna(string $query, mixed $parametri = [], $indiceColonna = 0) : array
  {
    $statement = $this->esegui($query, $parametri);
    $statement->setFetchMode(\PDO::FETCH_COLUMN, $indiceColonna);
    return $statement->fetchAll();
  }

  public function valore(string $query, mixed $parametri = []) : mixed
  {
    $statement = $this->esegui($query, $parametri);
    $statement->setFetchMode(\PDO::FETCH_COLUMN, 0);
    return $statement->fetch();
  }

  public function tabella(string $nome) : Tabella
  {
    return new Tabella($nome, $this);
  }

  public function indicizza(array $risultati, string $campo) : array
  {
    return array_column($risultati, null, $campo);
  }

  public function raggruppa(array $risultati, mixed $campo) : array
  {
    $filtro = is_callable($campo) ? $campo : fn($e) => ((array) $e)[$campo];
    $gruppi = [];
    foreach ($risultati as $elemento) {
      $chiave = $filtro($elemento);
      $gruppi[$chiave] ??= [];
      $gruppi[$chiave][] = $elemento;
    }
    return $gruppi;
  }

  private function espandiParametri(string $query, array $parametri) : array
  {
    foreach ($parametri as $chiave => $valore) {
      if (is_a($valore, \DateTimeInterface::class)) {
        $parametri[$chiave] = $valore->format('Y-m-d H:i:s');
      }
    }
    return $this->espandiParametriArray($query, $parametri);
  }

  private function espandiParametriArray(string $query, array $parametri) : array
  {
    $daAggiungere = [];
    $daTogliere = [];

    foreach ($parametri as $chiave => $valore) {
      if (!is_array($valore)) {
        continue;
      }

      # espandiamo l'array in parametri distinti
      $nuovi = [];
      foreach (array_values($parametri[$chiave]) as $i => $v) {
        $nuovi["{$chiave}_elemento_{$i}"] = $v; 
      }

      # aggiorniamo la query con i parametri definiti sopra
      $nuoviAssieme = implode(', ', array_map(fn($k) => ":{$k}", array_keys($nuovi)));
      $query = preg_replace(
        "#\s+IN\s*\(\s*:{$chiave}\s*\)|\s*=\s*:{$chiave}#i",
        " IN ({$nuoviAssieme})",
        $query
      );

      # mettiamo da parte i parametri da modificare
      $daTogliere[] = $chiave;
      $daAggiungere = array_merge($daAggiungere, $nuovi);
    }

    # sostituiamo il parametro vecchio con quelli nuovi
    $nuoviParametri = array_merge(
      array_diff_key($parametri, array_flip($daTogliere)),
      $daAggiungere
    );

    return [ $query, $nuoviParametri ];
  }
}

class Tabella
{
  public function __construct(
    private string $tabella,
    private Database $database,
  ) {
  }

  public function tutti(
    array $where = [],
    string $orderBy = null,
    int $limit = null,
    int $offset = null,
    string $classe = null,
  ) : array
  {
    $query = $this->preparaSelect('*', $where, $orderBy, $limit, $offset);
    $statement = $this->database->esegui($query, $where, $classe);
    return $statement->fetchAll();
  }

  public function uno(array $where, string $classe = null) : mixed
  {
    $query = $this->preparaSelect('*', $where, limit: 1);
    $statement = $this->database->esegui($query, $where, $classe);
    return $statement->fetch() ?: null;
  }

  public function conta(array $where = []) : int
  {
    $query = $this->preparaSelect('COUNT(*)', $where);
    $statement = $this->database->esegui($query, $where);
    $statement->setFetchMode(\PDO::FETCH_COLUMN, 0);
    return $statement->fetch();
  }

  public function inserisci(array $dati) : mixed
  {
    $query = "INSERT INTO `{$this->tabella}` {$this->preparaValues($dati)}";
    $statement = $this->database->esegui($query, $dati);
    return $this->database->pdo->lastInsertId();
  }

  public function sostituisci(array $dati) : mixed
  {
    $query = "REPLACE INTO `{$this->tabella}` {$this->preparaValues($dati)}";
    $statement = $this->database->esegui($query, $dati);
    return $this->database->pdo->lastInsertId();
  }

  public function aggiorna(array $where, array $dati) : int
  {
    # Visto che la query può cercare in ed aggiornare la stessa colonna,
    # rinominiamo un gruppo di parametri per evitare collisioni.
    $datiRinominati = array_combine(
      array_map(fn($k) => "_aggiorna_{$k}", array_keys($dati)),
      array_values($dati)
    );
    $set = join(
      ', ',
      array_map(fn($k) => "`{$k}`=:_aggiorna_{$k}", array_keys($dati))
    );

    $query = "UPDATE `{$this->tabella}` SET {$set} {$this->preparaWhere($where)}";
    $statement = $this->database->esegui($query, array_merge($where, $datiRinominati));
    return $statement->rowCount();
  }

  public function elimina(array $where)
  {
    $query = "DELETE FROM `{$this->tabella}` {$this->preparaWhere($where)}";
    return $this->database->esegui($query, $where);
  }

  private function preparaSelect(
    string $colonne,
    array $where,
    string $orderBy = null,
    int $limit = null,
    int $offset = null,
  ) : string
  {
    $query = "SELECT {$colonne} FROM `{$this->tabella}`";

    if ($where) {
      $query .= " {$this->preparaWhere($where)}";
    }
    if ($orderBy) {
      $query .= " ORDER BY {$orderBy}";
    }
    if ($limit) {
      $query .= " LIMIT {$limit}";
      if ($offset) {
        $query .= " OFFSET {$offset}";
      }
    }

    return $query;
  }

  private function preparaValues(array $campi) : string
  {
    $chiavi = array_keys($campi);
    $colonne = join(', ', $chiavi);
    $valori = join(', ', array_map(fn($c) => ":{$c}", $chiavi));
    return "({$colonne}) VALUES ({$valori})";
  }

  private function preparaWhere(array $campi) : string
  {
    return 'WHERE ' . join(
      ' AND ',
      array_map(fn($k) => "`{$k}`=:{$k}", array_keys($campi))
    );
  }
}
