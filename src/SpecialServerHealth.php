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
			'uptime_raw' => 'N/A',
			'uptime' => 'N/A'
		];
		try {
			if ( function_exists( 'wfGetDB' ) ) {
				$dbr = wfGetDB( DB_REPLICA );
			} else {
				$dbr = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			}

			// Query 1: Global status metrics (excluding Slow_queries)
			$res = $dbr->query(
				"SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Uptime')",
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
					} elseif ( $name === 'Uptime' ) {
						$metrics['uptime_raw'] = $val;
						$metrics['uptime'] = is_numeric( $val ) ? $this->formatUptime( (int)$val ) : $val;
					}
				}
			}

			// Query 2: Slow queries count in the last 24 hours solely from mysql.slow_log
			try {
				$resSlow = $dbr->query(
					"SELECT COUNT(*) AS cnt FROM mysql.slow_log WHERE start_time >= NOW() - INTERVAL 1 DAY",
					__METHOD__
				);
				if ( $resSlow ) {
					foreach ( $resSlow as $row ) {
						$metrics['slow'] = (int)( $row->cnt ?? $row->CNT ?? 0 );
					}
				}
			} catch ( \Throwable $e ) {
				$metrics['slow'] = 'N/A';
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
		$html .= Html::rawElement( 'h3', [ 'class' => 'perfmon-card-title' ],
			Html::element( 'span', [], $this->msg( 'mediawikiperfmon-cpu-load' )->text() ) .
			$this->getCpuHealthStatus( $cpuLoad )
		);
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
		$html .= Html::rawElement( 'h3', [ 'class' => 'perfmon-card-title' ],
			Html::element( 'span', [], $this->msg( 'mediawikiperfmon-mem-usage' )->text() ) .
			$this->getMemoryHealthStatus( $usedPercent )
		);
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

		// Threads status
		$threadsStatus = 'green';
		if ( is_numeric( $dbMetrics['threads'] ) ) {
			$tCount = (int)$dbMetrics['threads'];
			if ( $tCount > 150 ) {
				$threadsStatus = 'red';
			} elseif ( $tCount > 50 ) {
				$threadsStatus = 'amber';
			}
		}

		// Peak status
		$peakStatus = 'green';
		if ( is_numeric( $dbMetrics['peak'] ) ) {
			$pCount = (int)$dbMetrics['peak'];
			if ( $pCount > 200 ) {
				$peakStatus = 'red';
			} elseif ( $pCount > 100 ) {
				$peakStatus = 'amber';
			}
		}

		// Slow status
		$slowStatus = 'green';
		if ( is_numeric( $dbMetrics['slow'] ) ) {
			$sCount = (int)$dbMetrics['slow'];
			if ( $sCount > 50 ) {
				$slowStatus = 'red';
			} elseif ( $sCount > 0 ) {
				$slowStatus = 'amber';
			}
		}

		// Uptime status
		$dbUptimeStatus = 'green';
		if ( is_numeric( $dbMetrics['uptime_raw'] ) ) {
			$uSeconds = (int)$dbMetrics['uptime_raw'];
			if ( $uSeconds <= 900 ) {
				$dbUptimeStatus = 'amber';
			}
		}

		// 3. Database Health Card
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card perfmon-card-db' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-icon' ], '🗄️' );
		$html .= Html::rawElement( 'h3', [ 'class' => 'perfmon-card-title' ],
			Html::element( 'span', [], $this->msg( 'mediawikiperfmon-db-health' )->text() ) .
			$this->getDatabaseHealthStatus( $dbMetrics )
		);
		$html .= Html::openElement( 'div', [ 'class' => 'perfmon-card-value-group' ] );
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-threads' )->text() ) .
			$this->formatMetricValue( $dbMetrics['threads'], $threadsStatus )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-peak' )->text() ) .
			$this->formatMetricValue( $dbMetrics['peak'], $peakStatus )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-slow' )->text() ) .
			$this->formatMetricValue( $dbMetrics['slow'], $slowStatus )
		);
		$html .= Html::rawElement( 'div', [ 'class' => 'perfmon-card-subvalue' ],
			Html::element( 'span', [ 'class' => 'perfmon-label' ], $this->msg( 'mediawikiperfmon-db-uptime' )->text() ) .
			$this->formatMetricValue( $dbMetrics['uptime'], $dbUptimeStatus )
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

	/**
		* Determine health status for CPU Load.
		*
		* @param array $cpuLoad
		* @return string HTML status badge.
		*/
	private function getCpuHealthStatus( array $cpuLoad ): string {
		if ( $cpuLoad[0] === 'N/A' || !is_numeric( $cpuLoad[0] ) ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Unknown' );
		}

		$load = (float)$cpuLoad[0];
		$cores = $this->getCpuCoresCount();

		if ( $load <= 0.70 * $cores ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-green' ], 'Healthy' );
		} elseif ( $load <= 1.00 * $cores ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Warning' );
		} else {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-red' ], 'Critical' );
		}
	}

	/**
		* Count the number of CPU cores in the system by reading /proc/cpuinfo.
		* Secure fallback mechanism, executing no shell commands.
		*
		* @return int
		*/
	private function getCpuCoresCount(): int {
		$cores = 1;
		if ( is_readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = file_get_contents( '/proc/cpuinfo' );
			if ( $cpuinfo !== false ) {
				$cores = substr_count( $cpuinfo, 'processor' );
			}
		}
		return $cores > 0 ? $cores : 1;
	}

	/**
		* Determine health status for Memory Usage.
		*
		* @param int $usedPercent
		* @return string HTML status badge.
		*/
	private function getMemoryHealthStatus( int $usedPercent ): string {
		if ( $usedPercent === 0 ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Unknown' );
		}

		if ( $usedPercent <= 75 ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-green' ], 'Healthy' );
		} elseif ( $usedPercent <= 90 ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Warning' );
		} else {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-red' ], 'Critical' );
		}
	}

	/**
		* Determine health status for Database.
		*
		* @param array $dbMetrics
		* @return string HTML status badge.
		*/
	private function getDatabaseHealthStatus( array $dbMetrics ): string {
		$threads = $dbMetrics['threads'];
		$slow = $dbMetrics['slow'];
		$uptimeRaw = $dbMetrics['uptime_raw'];
		$peak = $dbMetrics['peak'];

		if ( $threads === 'N/A' || !is_numeric( $threads ) ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Unknown' );
		}

		$statuses = [];

		$threadsCount = (int)$threads;
		if ( $threadsCount > 150 ) {
			$statuses[] = 'red';
		} elseif ( $threadsCount > 50 ) {
			$statuses[] = 'amber';
		}

		if ( is_numeric( $peak ) ) {
			$peakCount = (int)$peak;
			if ( $peakCount > 200 ) {
				$statuses[] = 'red';
			} elseif ( $peakCount > 100 ) {
				$statuses[] = 'amber';
			}
		}

		$slowCount = is_numeric( $slow ) ? (int)$slow : 0;
		if ( $slowCount > 50 ) {
			$statuses[] = 'red';
		} elseif ( $slowCount > 0 ) {
			$statuses[] = 'amber';
		}

		if ( is_numeric( $uptimeRaw ) ) {
			$uptimeSec = (int)$uptimeRaw;
			if ( $uptimeSec <= 900 ) {
				$statuses[] = 'amber';
			}
		}

		if ( in_array( 'red', $statuses, true ) ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-red' ], 'Critical' );
		} elseif ( in_array( 'amber', $statuses, true ) ) {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-amber' ], 'Warning' );
		} else {
			return Html::rawElement( 'span', [ 'class' => 'perfmon-status-badge perfmon-status-green' ], 'Healthy' );
		}
	}

	/**
		* Render value with health class and warning/critical indicators next to it.
		*
		* @param string $val Raw value.
		* @param string $status 'green'|'amber'|'red'.
		* @return string HTML span element.
		*/
	private function formatMetricValue( $val, string $status ): string {
		$valStr = (string)$val;
		$class = 'perfmon-val';
		$icon = '';
		if ( $status === 'amber' ) {
			$class .= ' perfmon-metric-amber';
			$icon = ' ⚠️';
		} elseif ( $status === 'red' ) {
			$class .= ' perfmon-metric-red';
			$icon = ' ❌';
		}

		return Html::rawElement( 'span', [ 'class' => $class ], htmlspecialchars( $valStr ) . $icon );
	}
}
