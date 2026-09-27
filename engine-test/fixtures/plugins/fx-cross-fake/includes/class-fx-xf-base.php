<?php
class FX_XF_Base {
	public function money( $n ) {
		return money_format( '%i', $n );
	}
}
