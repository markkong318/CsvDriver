<?php
namespace Autobot;

class CsvDriver{
	protected $csv_file;
	protected $csv_header;

	protected $line_buf;

	protected $group_interval = 100;

	public function __construct($csv_file = ''){

		if($csv_file == ''){
			$this->csv_file = __DIR__.'/../../lake/gundam/data/csv/'.$this->snakize(array_pop(explode('\\', get_called_class()))).'.csv';
		}else{
			if(strpos($csv_file, '/') !== false) {
				$this->csv_file = $csv_file;
			}else{
				$this->csv_file = __DIR__.'/../../lake/gundam/data/csv/'.$csv_file;
			}
		}
		
		if(!file_exists($this->csv_file)){
			throw new \ProgrammingError('csv存在しない');
		}

		$this->csv_header = array();
		$this->line_buf = array();

		$this->read_header();

	}

	/**
	 * csvをheader読む
	 * @param $csv_file
	 */
	private function read_header(){
		$csv_file = $this->csv_file;

		$this->csv_header = array();

		$fp_r = fopen($csv_file, 'r');

		if ($fp_r) {

			while (($line = fgets($fp_r)) !== false) {

				if($this->startsWith($line, 'id')){

					$line = $this->process_read_line($line);

					
					$this->csv_header = $this->convert_str_to_header($line);

					break;
				}
			}
		}

		fclose($fp_r);

		if(!$this->csv_header){
			throw new \ProgrammingError('csv headerの読み込みが失敗');
		}
	}

	/**
	 * csv headerを取得する
	 * @return array
	 */
	public function get_header(){
		return $this->csv_header;
	}

	/**
	 * Bufより更新します
	 */
	public function update_csv(){

		// 更新予定データを出力
		$this->console("更新予定内容");
		foreach($this->line_buf as $key => $line){
			$this->console("    [$key] => $line");
		}

		// 内容がない場合に更新しない
		if(count($this->line_buf) == 0){
			return;
		}

		$csv_file = $this->csv_file;

		$buf_id_max = ~PHP_INT_MAX;
		$buf_id_min = PHP_INT_MAX;
		foreach($this->line_buf as $line){

			if(!$this->is_line_header($line) && !$this->is_line_comment($line) && !$this->is_line_empty($line)) {

				$data_array = $this->convert_str_to_array($line);

				$id = $data_array['id'];

				if ($id > $buf_id_max) {
					$buf_id_max = $id;
				}

				if ($id < $buf_id_min) {
					$buf_id_min = $id;
				}
			}
		}

		$this->console("更新ID範囲:$buf_id_min ~ $buf_id_max");
		
		if($buf_id_max == ~PHP_INT_MAX || $buf_id_min == PHP_INT_MAX){
			return;
		}

		$fp_r = fopen($csv_file, 'r');
		$fp_w = fopen($csv_file.'.tmp', 'w');

		if ($fp_r) {

			while (($line = fgets($fp_r)) !== false) {
				$line = $this->process_read_line($line);

				if($line == '') {

					$this->write_line($fp_w, $line);

				}else if($this->is_line_comment_text($line)){

					$this->write_line($fp_w, $line);

				}else if($this->is_line_header($line)){

					$this->write_line($fp_w, $line);

				}else if($this->is_line_empty($line)){

					$this->write_line($fp_w, $line);

				}else{

					// 今回のID
					$data_array = $this->convert_str_to_array($line);

					if($this->is_data_array_comment_data($data_array)){
						// コメントされた場合に

						preg_match('/^#(\d+)$/', $data_array['id'], $match);
						$id = $match[1];

					}else{
						// 通常のデータ

						$id = $data_array['id'];
					}

					if($id == $buf_id_min){
						// 最初読む時に(header以降)すぐマッチされる場合

						if(!$this->is_line_buf_empty()) {
							$this->flush_line_buf($fp_w);
							$this->reset_line_buf();

							$this->rewind_to_id($fp_r, $buf_id_max);
						}

					}else {

						$this->write_line($fp_w, $line);

						// 次可能のIDを先に読む
						$data_array_next = $this->get_next_data($fp_r);
						if ($data_array_next) {

							if($this->is_data_array_comment_data($data_array_next)){
								// コメントされた場合に

								preg_match('/^#(\d+)$/', $data_array_next['id'], $match);
								$id_next = $match[1];

							}else {
								// 通常のデータ

								$id_next = $data_array_next['id'];
							}


						} else {
							$id_next = null;
						}

						if ($id_next) {
							if ($buf_id_min > $id && $buf_id_min <= $id_next) {

								if (!$this->is_line_buf_empty()) {
									$this->flush_line_buf($fp_w);
									$this->reset_line_buf();

									$this->rewind_to_id($fp_r, $buf_id_max);
								}
							}
						} else {
							// ファイル最後段階、無条件出力

							if (!$this->is_line_buf_empty()) {

								$this->flush_line_buf($fp_w);
								$this->reset_line_buf();

							}
						}
					}

				}

			}

		}

		fclose($fp_w);
		fclose($fp_r);

		unlink($csv_file);
		rename($csv_file.'.tmp', $csv_file);
	}


	/**
	 * 次に出現するdataを取得
	 * @param $fp_r
	 * @return array
	 */
	private function get_next_data($fp_r){

		$pos = ftell($fp_r);

		$data_array = array();
		while (($line = fgets($fp_r)) !== false) {
			$line = $this->process_read_line($line);

			if($line &&
				!$this->is_line_comment_text($line) &&
				!$this->is_line_header($line) &&
				!$this->is_line_empty($line)) {

				$data_array = $this->convert_str_to_array($line);
				break;
			}
		}

		fseek($fp_r, $pos);

		return $data_array;
	}

	/**
	 * fpはidまで移動する(idは含まれません)
	 * @param $fp_r
	 * @param $dst_id
	 */
	private function rewind_to_id($fp_r, $dst_id){

		$pos = ftell($fp_r);

		$data_array = array();
		while (($line = fgets($fp_r)) !== false) {
			$line = $this->process_read_line($line);

			if($line && !$this->is_line_comment($line) && !$this->is_line_header($line) && !$this->is_line_empty($line)) {

				$data_array = $this->convert_str_to_array($line);

				$id = $data_array['id'];

				if($id == $dst_id) {
					// 同じIDがある場合に

					break;
				}else if($id > $dst_id){
					// idが存在しない場合

					fseek($fp_r, $pos);
					break;
				}

				$pos = ftell($fp_r);
			}
		}
	}

	protected function rewind_to_header($fp_r){

		$pos = ftell($fp_r);

		$data_array = array();
		while (($line = fgets($fp_r)) !== false) {
			$line = $this->process_read_line($line);

			if($line && $this->is_line_header($line) ) {

				break;
			}
		}
	}

	/**
	 * ヘダーを読む
	 * @param $line
	 * @return array
	 */
	public function convert_str_to_header($line){
		$csv_header = explode(',', $line);

		return $csv_header;
	}

	/**
	 * 通常のストリングはデータへ変換する
	 * @param $csv_header
	 * @param $line
	 * @return array
	 */
	public function convert_str_to_array($line){
		$csv_header = $this->csv_header;

		$line_array = explode(',', $line);
		$data_array = array();

		foreach($csv_header as $key => $field){
			$data_array[$field] = $line_array[$key];
		}

		return $data_array;
	}

	/**
	 * データは出力用ストリングへ変換する
	 * @param $csv_header
	 * @param $data_array
	 * @return string
	 */
	private function convert_array_to_str($data_array){
		$csv_header = $this->csv_header;

		$line_array = array();

		foreach($csv_header as $key => $field){
			$line_array[$key] = $data_array[$field];
		}

		$line = implode(',', $line_array);

		return $line;
	}

	/**
	 * コメントは出力用ストリングへ変換する
	 * @param $csv_header
	 * @param $comment
	 * @return string
	 */
	private function convert_comment_to_str($comment){
		$csv_header = $this->csv_header;

		$line_array = array();

		foreach($csv_header as $key => $field){
			if($key == 0){
				$line_array[0] = '# '.$comment;
			}else {
				$line_array[$key] = '';
			}
		}

		$line = implode(',', $line_array);

		return $line;
	}

	/**
	 * 新しい空データを取得
	 * @param $csv_header
	 * @return array
	 */
	public function get_empty_data_array(){
		$csv_header = $this->csv_header;

		$data_array = array();
		foreach($csv_header as $key => $field){
			$data_array[$field] = '';
		}

		return $data_array;
	}

	/**
	 * データをリセットする
	 * @param $data_array
	 * @return mixed
	 */
	public function reset_data_array($data_array){
		foreach($data_array as $key => &$value){
			$value = '';
		}

		return $data_array;
	}

	public function get_comment_from_data_array($data_array){
		$comment = $data_array['id'];
		
		$comment = str_replace('# ', '', $comment);
		$comment = str_replace('#', '', $comment);
		
		return $comment;
	}

	/**
	 * コメントはデータへ書き込む
	 * @param $data_array
	 * @param $comment
	 * @return mixed
	 */
	public function write_comment_to_data_array($data_array, $comment){
		$data_array = $this->reset_data_array($data_array);

		$data_array['id'] = '# '.$comment;
		
		return $data_array;
	}

	/**
	 * データを書き込む
	 * @param $fp_w
	 * @param $csv_header
	 * @param $data_array
	 */
	private function write_data_array($fp_w, $data_array){
		$line = $this->convert_array_to_str($data_array);

		$this->write_line($fp_w, $line);
	}


	/**
	 * コメントを書き込む
	 * @param $fp_w
	 * @param $csv_header
	 * @param $comment
	 */
	private function write_comment($fp_w, $comment){
		$line = $this->convert_comment_to_str($comment);

		$this->write_line($fp_w, $line);
	}

	/**
	 * Bufへデータを書き込む
	 * @param $data_array
	 */
	public function write_data_array_to_line_buf($data_array){
		$line = $this->convert_array_to_str($data_array);

		$this->write_line_buf($line);
	}

	/**
	 * Bufへデータリストを書き込む
	 * @param $data_array_list
	 */
	public function write_data_array_list_to_line_buf($data_array_list){
		foreach($data_array_list as $data_array){
			$this->write_data_array_to_line_buf($data_array);
		}
	}

	/**
	 * Bufへコメントを書き込む
	 * @param $comment
	 */
	public function write_comment_to_line_buf($comment){
		$line = $this->convert_comment_to_str($comment);

		$this->write_line_buf($line);
	}

	/**
	 * ストリングはBufへ書き込む
	 * @param $line
	 */
	public function write_line_buf($line){
		$this->line_buf[] = $line;
	}

	/**
	 * Bufを出力する
	 * @param $fp_w
	 */
	public function flush_line_buf($fp_w){
		foreach($this->line_buf as $line){
			$this->write_line($fp_w, $line);
		}
	}

	/**
	 * Bufをリセットする
	 */
	public function reset_line_buf(){
		$this->line_buf = array();
	}

	/**
	 * Bufは空ですか
	 * @return bool
	 */
	public function is_line_buf_empty(){
		return !$this->line_buf;
	}

	/**
	 * ストリングを出力
	 * @param $fp_w
	 * @param $line
	 */
	public function write_line($fp_w, $line){
		mb_language('Japanese');
		$line = mb_convert_encoding($line, 'SJIS', 'auto');
		fwrite($fp_w, $line."\n");
	}

	/**
	 * ストリングにデータがないですか
	 * @param $line
	 * @return bool
	 */
	public function is_line_empty($line){
		if(!$this->startsWith($line, ',')){
			return false;
		}

		return true;
	}

	/**
	 * ストリングはコメントですか (テキストとデータ2種類を含む)
	 * @param $line
	 * @return bool
	 */
	public function is_line_comment($line){
		if(!$this->startsWith($line, '#')){
			return false;
		}

		return true;
	}

	/**
	 * ストリングはテキストコメントですか
	 * @param $line
	 * @return bool
	 */
	public function is_line_comment_text($line){
		if(!$this->is_line_comment($line)){
			return false;
		}

		if($this->is_line_comment_data($line)){
			return false;
		}

		return true;
	}

	/**
	 * ストリングはコメントされたデータですか
	 * @param $line
	 * @return bool
	 */
	public function is_line_comment_data($line){
		if(!preg_match('/^#(\d+),/', $line, $match)){
			return false;
		}

		return true;
	}

	/**
	 * ストリングはHeaderですか
	 * @param $line
	 * @return bool
	 */
	public function is_line_header($line){
		if(!$this->startsWith($line, 'id')){
			return false;
		}

		return true;
	}

	/**
	 * データはコメントですか(テキストとデータ2種類を含む)
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_comment($data_array){
		if(!$this->startsWith($data_array['id'], '#')){
			return false;
		}

		return true;
	}

	/**
	 * データはテキストコメントされたデータですか
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_comment_text($data_array){
		if(!$this->is_data_array_comment($data_array)){
			return false;
		}

		if($this->is_data_array_comment_data($data_array)){
			return false;
		}

		return true;
	}

	/**
	 * データはコメントされたデータですか
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_comment_data($data_array){
		if(!preg_match('/^#(\d+)$/', $data_array['id'], $match)){
			return false;
		}

		return true;
	}

	/**
	 * データはHeaderですか
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_header($data_array){
		if(!$this->startsWith($data_array['id'], 'id')){
			return false;
		}

		return true;
	}

	/**
	 * データは空ですか
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_empty($data_array){
		if(!$data_array['id'] == ''){
			return false;
		}

		return true;
	}
	
	/**
	 * データは有効データですか
	 * @param $data_array
	 * @return bool
	 */
	public function is_data_array_data($data_array){
		if($this->is_data_array_comment($data_array)){
			return false;
		}

		if($this->is_data_array_comment_data($data_array)){
			return false;
		}

		if($this->is_data_array_header($data_array)){
			return false;
		}

		if($this->is_data_array_empty($data_array)){
			return false;
		}
		
		return true;
	}

	/**
	 * データリストはfuncで取り出す
	 * @param Closure $test_func
	 * @return array
	 */
	public function get_data_array_list_by_func(\Closure $test_func){
		$csv_file = $this->csv_file;

		$data_array_list = array();

		$fp_r = fopen($csv_file, 'r');

		// 連続マッチする用フラグ
		$is_match = false;

		if ($fp_r) {

			$this->rewind_to_header($fp_r);

			while (($line = fgets($fp_r)) !== false) {
				$line = $this->process_read_line($line);

				if(!$is_match){
					if($this->is_line_header($line) || $this->is_line_comment_data($line)){
						continue;
					}
				}

				// 次可能なデータを取り出す
				if($this->is_line_comment($line) || $this->is_line_empty($line)) {
					$next_data_array = $this->get_next_data($fp_r);
				}else{
					$next_data_array = $this->convert_str_to_array($line);
				}

				if($next_data_array) {

					// 条件チェック
					if ($test_func($next_data_array)) {

						// 現在から次までデータを保存する
						$data_array = $this->convert_str_to_array($line);
						if($data_array['id'] != $next_data_array['id']){

							$data_array_list[] = $data_array;

							while (($line = fgets($fp_r)) !== false) {
								$line = $this->process_read_line($line);

								$data_array = $this->convert_str_to_array($line);

								if($data_array['id'] < $next_data_array['id']){
									$data_array_list[] = $data_array;
								}else{
									break;
								}
							}
						}

						// 最後は次のデータを保存する
						$data_array_list[] = $next_data_array;

						$is_match = true;

					} else {

						if ($is_match) {
							break;
						}
					}
				}
			}
		}

		fclose($fp_r);
		
		return $data_array_list;
	}

	/**
	 * データはfuncで取り出す
	 * @param Closure $test_func
	 * @return mixed
	 */
	public function get_data_array_by_func(\Closure $test_func){
		$data_array_list = $this->get_data_array_list_by_func($test_func);
		
		return reset($data_array_list);
	}
	
	/**
	 * データはコメント判断で取り出す
	 * @param $comment
	 * @param \Closure $ignore_func
	 * @return array
	 */
	public function get_data_array_list_by_comment($comment, \Closure $ignore_func = null){
		
		$csv_file = $this->csv_file;

		// 出力配列
		$data_array_list = array();

		$fp_r = fopen($csv_file, 'r');

		// 連続マッチする用フラグ
		$is_match = false;

		if($fp_r) {

			$this->rewind_to_header($fp_r);
			
			while (($line = fgets($fp_r)) !== false) {
				$line = $this->process_read_line($line);

				if (!$is_match) {
					if ($this->is_line_header($line) || $this->is_line_comment_data($line) || $this->is_line_empty($line)) {
						continue;
					}
				}

				$data_array = $this->convert_str_to_array($line);

				if ($this->is_line_comment($line) && !$this->is_line_comment_data($line)) {

					if ($is_match) {

						// is_match:1状態に特定フォーマットのコメントを無条件にリストに追加します
						if($ignore_func && $ignore_func($this->get_comment_from_data_array($data_array))){

							$data_array_list[] = $data_array;

						}else {
							break;
						}
					} else {
						
						// コメントの一致チェック
						if ($data_array['id'] == '# ' . $comment || $data_array['id'] == '#' . $comment) {

							$is_match = true;

							$data_array_list[] = $data_array;
						}
					}
				} else {

					if ($is_match) {
						$data_array_list[] = $data_array;
					}
				}


			}
		}

		fclose($fp_r);

		return $data_array_list;
	}

	/**
	 * グループの間隔で新IDを編成する
	 * @param $data_array_list
	 * @param int $my_based_id
	 * @return mixed
	 */
	public function update_new_data_array_list_ids($data_array_list, $my_based_id = 0){


		// 書き込むデータに一番小さいIDを取得
		$based_id = 0;
		foreach($data_array_list as $data_array){
			if($this->is_data_array_data($data_array)){
				$based_id = $data_array['id'];

				break;
			}
		}

		if($my_based_id){
			// 指定された新ID

			$next_based_id = $my_based_id;
		}else{
			$next_based_id = $based_id;

			// 次の使ってないIDを探す
			$csv_file = $this->csv_file;

			$fp_r = fopen($csv_file, 'r');

			if ($fp_r) {

				while (($line = fgets($fp_r)) !== false) {
					$data_array = $this->convert_str_to_array($line);

					// 使われたIDをチェックする
					if (($data_array['id'] >= $next_based_id && $data_array['id'] < ($next_based_id + $this->group_interval))) {
						$next_based_id += $this->group_interval;
					}
				}
			}

			fclose($fp_r);
		}

		// 新IDを旧IDを差分
		$diff_based_id = $next_based_id - $based_id;

		if($diff_based_id == 0){
			return $data_array_list;
		}

		foreach($data_array_list as &$data_array){

			// データを確認
			if(!$this->is_data_array_comment($data_array) && !$this->is_data_array_header($data_array) && !$this->is_data_array_empty($data_array)){
				$data_array['id'] += $diff_based_id;
			}

			// コメントされたデータを確認
			if($this->is_data_array_comment_data($data_array)){

				if(preg_match('/^#(\d+)$/', $data_array['id'], $match)){
					$new_id = $match[1] +  $diff_based_id;

					$data_array['id'] = '#'.$new_id;
				}
			}
		}

		return $data_array_list;
	}

	/**
	 * イベントIDの間に間隔
	 * @param $group_interval
	 */
	public function set_group_interval($group_interval){
		$this->group_interval = $group_interval;
	}

	/**
	 * ファイルのラインを読む時に前処理
	 * @param $line
	 * @return mixed|string
	 */
	protected function process_read_line($line){
		// 最後の改行を削除する
		$line = preg_replace("/(.*)\n$/", '${1}', $line);

		// 文字コードを変換
		$line= mb_convert_encoding($line,"utf-8","sjis");

		return $line;
	}

	/**
	 * 内部間隔を取得
	 * @return int
	 */
	public function get_group_interval(){
		return $this->group_interval;
	}

	/**
	 * snake -> camel変換
	 * @param $str
	 * @return mixed
	 */
	private function camelize($str) {
		$str = ucwords($str, '_');
		return str_replace('_', '', $str);
	}

	/**
	 * camel -> snake変換
	 * @param $str
	 * @return string
	 */
	private function snakize($str) {
		$str = preg_replace('/[a-z]+(?=[A-Z])|[A-Z]+(?=[A-Z][a-z])/', '\0_', $str);
		return strtolower($str);
	}

	/**
	 * start with判定
	 * @param $haystack
	 * @param $needle
	 * @return bool
	 */
	private function startsWith($haystack, $needle){
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	/**
	 * end with判定
	 * @param $haystack
	 * @param $needle
	 * @return bool
	 */
	private function endsWith($haystack, $needle){
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}

		return (substr($haystack, -$length) === $needle);
	}

	/**
	 * コンソール用出力
	 * @param $text
	 */
	private function console($text){
		$is_cli = ( php_sapi_name() == 'cli' );

		if($is_cli){
			echo $text."\n";
		}
	}
}