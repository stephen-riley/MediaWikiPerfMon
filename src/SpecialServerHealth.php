<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiPerfMon;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;

/**
	* Special page for displaying server performance and health metrics.
	*/
class SpecialServerHealth extends SpecialPage {

	public function __construct() {
		parent::__construct( 'ServerHealth', 'serverhealth-view' );
	}

	/**
		* Execute the special page and render the metrics.
		*
		* @param string|null $subPage
		* @return void
		*/
	public function execute( $subPage ) {
		$this->checkPermissions();
		$this->setHeaders();
		$out = $this->getOutput();

		// Enforce styles
		$out->addModuleStyles( 'ext.MediaWikiPerfMon.styles' );

		// Retrieve all performance metrics
		$cpuLoad = $this->getCPULoad();
		$systemUptime = $this->getSystemUptime();
		$memory = $this->getMemoryUsage();
		$dbMetrics = $this->getDatabaseMetrics();

		// Render the metrics dashboard
		$this->renderDashboard( $cpuLoad, $systemUptime, $memory, $dbMetrics );
	}

	/**
		* Parse the first three values from cat /proc/loadavg using shell_exec().
		* Hardcoded shell command to prevent any user input execution.
		*
		* @return array
		*/
	private function getCPULoad(): array {
		$output = shell_exec( 'cat /proc/loadavg' );
		if ( $output === null || $output === false ) {
			return [ 'N/A', 'N/A', 'N/A' ];
		}

		$parts = preg_split( '/\s+/', trim( $output ) );
		if ( $parts !== false && count( $parts ) >= 3 ) {
			return [ $parts[0], $parts[1], $parts[2] ];
		}

		return [ 'N/A', 'N/A', 'N/A' ];
	}

	/**
		* Parse total and available memory columns from free -m using shell_exec().
		* Hardcoded shell command to prevent any user input execution.
		*
		* @return array [ 'total' => string, 'available' => string ]
		*/
	private function getMemoryUsage(): array {
		$output = shell_exec( 'free -m' );
		$total = 'N/A';
		$available = 'N/A';

		if ( $output !== null && $output !== false ) {
			$lines = explode( "\n", $output );
			$headers = [];
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( stripos( $line, 'total' ) !== false && stripos( $line, 'available' ) !== false ) {
					$headers = preg_split( '/\s+/', $line );
				} elseif ( strpos( $line, 'Mem:' ) === 0 || strpos( $line, 'Mem ' ) === 0 ) {
					$memData = preg_split( '/\s+/', $line );
					if ( $headers && $memData !== false ) {
						$totalIndex = array_search( 'total', $headers );
						$availableIndex = array_search( 'available', $headers );
						if ( $totalIndex !== false && isset( $memData[$totalIndex + 1] ) ) {
							$total = $memData[$totalIndex + 1];
						}
						if ( $availableIndex !== false && isset( $memData[$availableIndex + 1] ) ) {
							$available = $memData[$availableIndex + 1];
						}
					} elseif ( $memData !== false ) {
						// Fallback to standard indices
						if ( isset( $memData[1] ) ) {
							$total = $memData[1];
						}
						if ( isset( $memData[6] ) ) {
							$available = $memData[6];
						}
					}
				}
			}
		}

		return [
			'total' => $total,
			'available' => $available
		];
	}

	/**
		* Parse system uptime from /proc/uptime using shell_exec().
		* Hardcoded shell command to prevent any user input execution.
		*
		* @return string
		*/
	private function getSystemUptime(): string {
		$output = shell_exec( 'cat /proc/uptime' );
		if ( $output === null || $output === false ) {
			return 'N/A';
		}

		$parts = preg_split( '/\s+/', trim( $output ) );
		if ( $parts !== false && count( $parts ) >= 1 && is_numeric( $parts[0] ) ) {
			return $this->formatUptime( (int)$parts[0] );
		}

		return 'N/A';
	}

	/**
		* Query database status metrics.
		*
		* @return array
		*/
	private function getDatabaseMetrics(): array {
		$metrics = [
			'threads' => 'N/A',
			'peak' => 'N/A',
			'slow' => 'N/A',
			'uptime' => 'N/A'
		];
		try {
			if ( function_exists( 'wfGetDB' ) ) {
				$dbr = wfGetDB( DB_REPLICA );
			} else {
				$dbr = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			}
			$res = $dbr->query(
				"SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Slow_queries', 'Uptime')",
				__METHOD__
			);
			if ( $res ) {
				foreach ( $res as $row ) {
					$name = $row->Variable_name ?? $row->variable_name ?? '';
					$val = $row->Value ?? $row->value ?? 'N/A';
					if ( $name === 'Threads_connected' ) {
						$metrics['threads'] = $val;
					} elseif ( $name === 'Max_used_connections' ) {
						$metrics['peak'] = $val;
					} elseif ( $name === 'Slow_queries' ) {
						$metrics['slow'] = $val;
					} elseif ( $name === 'Uptime' ) {
						$metrics['uptime'] = is_numeric( $val ) ? $this->formatUptime( (int)$val ) : $val;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Fail gracefully
		}
		return $metrics;
	}

	/**
		* Helper function to format seconds into days, hours, and minutes.
		*
		* @param int $seconds
		* @return string
		*/
	private function formatUptime( int $seconds ): string {
		$days = (int)( $seconds / 86400 );
		$seconds %= 86400;
		$hours = (int)( $seconds / 3600 );
		$seconds %= 3600;
		$minutes = (int)( $seconds / 60 );

		$parts = [];
		if ( $days > 0 ) {
			$parts[] = $days . 'd';
		}
		if ( $hours > 0 || $days > 0 ) {
			$parts[] = $hours . 'h';
		}
		$parts[] = $minutes . 'm';

		return implode( ' ', $parts );
	}

	/**
		* Render dashboard markup.
		*
		* @param array $cpuLoad
		* @param string $systemUptime
		* @param array $memory
		* @param array $dbMetrics
		* @return void
		*/
	private function renderDashboard( array $cpuLoad, string $systemUptime, array $memory, array $dbMetrics ): void {
		$out = $this->getOutput();

		$html = Html::openElement( 'div', [ 'class' => 'perfmon-dashboard' ] );

		// 1. CPU Load Card
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card perfmon-card-cpu' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-icon' ], '⚡' );
		$html .= Html::element( 'h3', [ 'class' => 'perfmon-card-title' ], $this->msg( 'mediawikiperfmon-cpu-load' )->text() );
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card-value-group' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-cpu-1min' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $cpuLoad[0] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-cpu-5min' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $cpuLoad[1] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-cpu-15min' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $cpuLoad[2] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-cpu-uptime' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $systemUptime )
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::element( 'p', [ 'class' => 'perfmon-card-desc' ], $this->msg( 'mediawikiperfmon-cpu-desc' )->text() );
		$html .= Html::closeElement( 'div' );

		// Calculate used memory and progress percent
		$usedVal = 'N/A';
		$usedPercent = 0;
		if ( is_numeric( $memory['total'] ) && is_numeric( $memory['available'] ) && $memory['total'] > 0 ) {
			$used = (int)$memory['total'] - (int)$memory['available'];
			$usedVal = $used . ' MB';
			$usedPercent = (int)round( ( $used / (float)$memory['total'] ) * 100 );
		}

		$totalVal = is_numeric( $memory['total'] ) ? $memory['total'] . ' MB' : 'N/A';
		$availableVal = is_numeric( $memory['available'] ) ? $memory['available'] . ' MB' : 'N/A';

		// 2. Memory Usage Card
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card perfmon-card-mem' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-icon' ], '💾' );
		$html .= Html::element( 'h3', [ 'class' => 'perfmon-card-title' ], $this->msg( 'mediawikiperfmon-mem-usage' )->text() );
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card-value-group' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-mem-total' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $totalVal )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-mem-used' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $usedVal )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-mem-available' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $availableVal )
		);

		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-progress-bar-container' ],
			Html::rawElement( 'div', [ 'class' => 'perfmon-progress-bar', 'style' => "width: {$usedPercent}%" ], '' )
		);
		$html .= Html::element( 'span', [ 'class' => 'perfmon-progress-text' ],
			$this->msg( 'mediawikiperfmon-mem-used-pct', $usedPercent )->text()
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::element( 'p', [ 'class' => 'perfmon-card-desc' ], $this->msg( 'mediawikiperfmon-mem-desc' )->text() );
		$html .= Html::closeElement( 'div' );

		// 3. Database Health Card
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card perfmon-card-db' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-icon' ], '🗄️' );
		$html .= Html::element( 'h3', [ 'class' => 'perfmon-card-title' ], $this->msg( 'mediawikiperfmon-db-health' )->text() );
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card-value-group' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-threads' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $dbMetrics['threads'] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-peak' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $dbMetrics['peak'] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-slow' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $dbMetrics['slow'] )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-uptime' )->text() ) .
			Html::element( 'span', [ 'class' => 'perfmon-val' ], $dbMetrics['uptime'] )
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::element( 'p', [ 'class' => 'perfmon-card-desc' ], $this->msg( 'mediawikiperfmon-db-desc' )->text() );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' ); // perfmon-dashboard

		// 4. Collapsible Slow Queries List (Client-side HTML5 details/summary)
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-slow-queries-container' ] );
		$html .= Html::openElement( 'details', [ 'class' => 'perfmon-details' ] );
		$html .= Html::rawElement( 'summary', [ 'class' => 'perfmon-summary' ], Html::element( 'strong', [], $this->msg( 'mediawikiperfmon-slow-queries-toggle' )->text() ) );

		$slowQueries = $this->getSlowQueriesList();

		if ( $slowQueries === 'permission-denied' ) {
			$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-info-box perfmon-warning' ],
				Html::element( 'p', [], $this->msg( 'mediawikiperfmon-slow-queries-denied' )->text() ) .
				Html::element( 'pre', [], "GRANT SELECT ON mysql.slow_log TO '" . $this->getDBUser() . "'@'localhost';" )
			);
		} elseif ( $slowQueries === 'error' ) {
			$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-info-box perfmon-error' ],
				Html::element( 'p', [], $this->msg( 'mediawikiperfmon-slow-queries-error' )->text() )
			);
		} elseif ( empty( $slowQueries ) ) {
			$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-info-box perfmon-info' ],
				Html::element( 'p', [], $this->msg( 'mediawikiperfmon-slow-queries-empty' )->text() )
			);
		} else {
			$html .= Html::openElement( 'table', [ 'class' => 'wikitable perfmon-slow-queries-table', 'style' => 'width:100%;' ] );
			$html .= Html::rawElement( 'thead', [],
				Html::rawElement( 'tr', [],
					Html::element( 'th', [], 'Time' ) .
					Html::element( 'th', [], 'Duration' ) .
					Html::element( 'th', [], 'Database' ) .
					Html::element( 'th', [], 'Query' )
				)
			);
			$html .= Html::openElement( 'tbody' );
			foreach ( $slowQueries as $q ) {
				$html .= Html::rawElement( 'tr', [],
					Html::element( 'td', [], $q['time'] ) .
					Html::element( 'td', [], $q['duration'] ) .
					Html::element( 'td', [], $q['db'] ) .
					Html::rawElement( 'td', [], Html::element( 'code', [], $q['sql'] ) )
				);
			}
			$html .= Html::closeElement( 'tbody' );
			$html .= Html::closeElement( 'table' );
		}

		$html .= Html::closeElement( 'details' );
		$html .= Html::closeElement( 'div' );

		$out->addHTML( $html );
	}

	/**
		* Retrieve the database username from system configurations.
		*
		* @return string
		*/
	private function getDBUser(): string {
		global $wgDBuser, $wgDBservers;
		if ( isset( $wgDBservers[0]['user'] ) ) {
			return (string)$wgDBservers[0]['user'];
		}
		return (string)( $wgDBuser ?? 'wikiuser' );
	}

	/**
		* Fetch list of slow queries from mysql.slow_log.
		*
		* @return array|string Array of queries, or error status code string.
		*/
	private function getSlowQueriesList() {
		try {
			if ( function_exists( 'wfGetDB' ) ) {
				$dbr = wfGetDB( DB_REPLICA );
			} else {
				$dbr = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			}

			$res = $dbr->query(
				"SELECT start_time, query_time, db, sql_text FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10",
				__METHOD__
			);

			$queries = [];
			if ( $res ) {
				foreach ( $res as $row ) {
					$queries[] = [
						'time' => $row->start_time ?? $row->START_TIME ?? 'N/A',
						'duration' => $row->query_time ?? $row->QUERY_TIME ?? 'N/A',
						'db' => $row->db ?? $row->DB ?? 'N/A',
						'sql' => $row->sql_text ?? $row->SQL_TEXT ?? 'N/A'
					];
				}
			}
			return $queries;
		} catch ( \Throwable $e ) {
			if ( strpos( $e->getMessage(), 'denied' ) !== false ) {
				return 'permission-denied';
			}
			return 'error';
		}
	}
}
