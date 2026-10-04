<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Image\Tests\Support;

/**
 * ICC profile fixtures and builders for color tests.
 *
 * Profiles are from the Compact ICC Profiles project (Clinton Ingram), CC0 1.0:
 * https://github.com/saucecontrol/Compact-ICC-Profiles
 */
final class IccFixtures {

	private const ADOBE_COMPAT =
		'AAAB4EFEQkUEIAAAbW50clJHQiBYWVogB+UABAAbAAoAGwAAYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5kQJL/Hmc0tW0AHE42cz9OcQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAAAPwAAAAk' .
		'Y3BydAAAASAAAAAid3RwdAAAAUQAAAAUY2hhZAAAAVgAAAAsclhZWgAAAYQAAAAUZ1hZWgAAAZgAAAAUYlhZWgAAAawAAAAU' .
		'clRSQwAAAcAAAAAgZ1RSQwAAAcAAAAAgYlRSQwAAAcAAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAIAAAAHABBADkAOABD' .
		'bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDEIAAAXe' .
		'///zJQAAB5MAAP2Q///7of///aIAAAPcAADAblhZWiAAAAAAAACcGAAAT6UAAAT8WFlaIAAAAAAAADSNAACgLAAAD5VYWVog' .
		'AAAAAAAAJjEAABAvAAC+nHBhcmEAAAAAAAMAAAACMzMAAQAAAAAAAAAACAAAAA5B';

	private const SGREY =
		'AAABaGxjbXMEIAAAbW50ckdSQVlYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5ku7piCLumsA/Jemm3Z6btwQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAFZGVzYwAAAMAAAAAk' .
		'Y3BydAAAAOQAAAAid3RwdAAAAQgAAAAUY2hhZAAAARwAAAAsa1RSQwAAAUgAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAI' .
		'AAAAHABzAEcAcgB5bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMy' .
		'AAAAAAABDD8AAAXd///zJgAAB5AAAP2S///7of///aIAAAPcAADAcXBhcmEAAAAAAAMAAAACZmkAAPKnAAANWQAAE9AAAApb';

	private const PROPHOTO =
		'AAAB4GxjbXMEIAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5kH9ZD3wQwsLzdCGIbXzs4jAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAAAPwAAAAk' .
		'Y3BydAAAASAAAAAid3RwdAAAAUQAAAAUY2hhZAAAAVgAAAAsclhZWgAAAYQAAAAUZ1hZWgAAAZgAAAAUYlhZWgAAAawAAAAU' .
		'clRSQwAAAcAAAAAgZ1RSQwAAAcAAAAAgYlRSQwAAAcAAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAIAAAAHABSAE8ATQBN' .
		'bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAAA//3////+' .
		'/////v////0AAQAD//////////8AAAABAAD/71hZWiAAAAAAAADMNwAASb4AAAAAWFlaIAAAAAAAACKaAAC2PQAAAAFYWVog' .
		'AAAAAAAACAUAAAAFAADTLHBhcmEAAAAAAAMAAAABzM0AAQAAAAAAAAAAEAAAAAgA';

	private const REC2020 =
		'AAAB4GxjbXMEIAAAbW50clJHQiBYWVogB+IAAwAUAAkADgAdYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5kHJ6znY22ptICfEF4bzi24AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAAAPwAAAAk' .
		'Y3BydAAAASAAAAAid3RwdAAAAUQAAAAUY2hhZAAAAVgAAAAsclhZWgAAAYQAAAAUZ1hZWgAAAZgAAAAUYlhZWgAAAawAAAAU' .
		'clRSQwAAAcAAAAAgZ1RSQwAAAcAAAAAgYlRSQwAAAcAAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAIAAAAHAAyADAAMgAw' .
		'bWx1YwAAAAAAAAABAAAADGVuVVMAAAAGAAAAHABDAEMAMAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDEIAAAXe' .
		'///zJQAAB5MAAP2Q///7of///aIAAAPcAADAblhZWiAAAAAAAACsaQAAR3D///+BWFlaIAAAAAAAACpqAACs4wAAB61YWVog' .
		'AAAAAAAAIAMAAAutAADL/3BhcmEAAAAAAAMAAAACOOUAAOjgAAAXIAAAOOQAABS9';

	private const CGATS001_CMYK =
		'AAAhEEFEQkUCEAAAc2NuckNNWUtMYWIgB+YADAAHABYAEAAqYWNzcE1TRlQAAAAAc2F3c2N0cmwAAAAAAAAAAAAAAAAAAPbW' .
		'AAEAAAAA0y1oYW5kw2fR0RrZabY+OZwwFrOlWAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEZGVzYwAAALQAAABf' .
		'Y3BydAAAANAAAAAMd3RwdAAAANwAAAAUQTJCMAAAAPAAACAgZGVzYwAAAAAAAAAFdUNNWQAAAAAAAAAAAAAAAHRleHQAAAAA' .
		'Q0MwAFhZWiAAAAAAAAD21gABAAAAANMtbWZ0MgAAAAAEAwYAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAA' .
		'ADAAAgAACiMQABabHJYjCCklL0Q1UztaQVpHTk1KUzBZGV71ZLxqhXArdcF7T4C7hheLa5Cmlc6a9KAVpR6qKK8ztEC5R75R' .
		'w1vIZ81k0lXXQdwh4PzlvupX7trzivgX/Gj//wAACNYNzhNiGIEeBiNNKKAuADNWOME+KUOlSSVOolQzWa9fLmStagpva3S/' .
		'efR/JIRSiWKOb5N/mJOdl6Ksp9OtDrJYt7q9NMLHyFrN9NOa2UjfAeSS6hPv1/WE+wf//wAACR4OLBPIGN4eYyOWKMgt9jMj' .
		'OFQ9ikLRSCFNflLqWFld02NKaL5uL3OUeO1+O4N+iLSN55MdmFqdqaMSqJ6uT7QpuifARcZyzKTSx9jJ3qHkSemg7qrz9Pi6' .
		'/OH//wAACIcNORJzFzwceyFqJl4rXDBjNW46hT+jRMpJ9E8jVE1ZbV6LY55oqm2wcqx3nHyHgXCGSosjj/6U35nJnsKj0aj7' .
		'rkOzo7kkvsrEkcp90IXWptzw413qdvHB+Sj///8AgACAANZuf7V++61Wf5Z+WIM1f7p+U1ZOgDJ+wB9kgf6AHPt4fauSaNP3' .
		'fZuOoasufcKLaoEgfiWI3VQ5fsSG5hyqgD6GY/hne+2lC9FMe/melKjRfESYwH8WfNWT2lJufYuPdBpJfpGMavXTesm3+87T' .
		'es2uzaageyOmhX0qe8ifNFDZfI2X8xhSfQqQBPPHegnLYczPefe/X6TNekq0qXuzewGrRk+Qe8mjnxbIe7+PZ/IqeYzfhMsy' .
		'eWLQfaNJeanDbHpzemu4Vk6WeziuYBWeesiOnePpjqh9Ob/QjDZ8rZrMih58jXQyiHx890pUh3l9xxQiibF/7ODvjKyN5r3B' .
		'ikmK45jxiFuIdHJXhu6Gq0iBhhKFjxIth8qFpd5siw2fCLtYiMiZs5bAhvWU+3CBha2RGEb5hOWOIhBhhhuJ69w6ifWwXbk5' .
		'h7ioy5TMhe+h7m7UhKqb80Wpg+uXqQ8JhKWKK9pxiS3CLreJhvK4V5M/hSuvX22Qg/OnpkShgymhpg4Hg4eJfNkMiJjU5bY1' .
		'hmPIpJH6hJi9mWyFg2e0m0PRgqWqIQ1EgreI+MnVnet7Lql5mXt66og1lXV7E2UMkg57wT4Nj7981glUkMx/u8dUnEmKa6fN' .
		'l8aIB4a5k9mGKmOWkJmE9jynjmKEhwhxjn6EJMVxmsqZ7aXrlmKVe4T3kneRpWIEj1KOpztdjSqMEwe2jJ6FN8OmmcGp5aQy' .
		'lV6jhoNCkYed2WCVjlyZGjpRjDOWTwcZiymEzMIqmQq6MKLWlKax7IH9kNKqe1+Kja+kdDmLi26fDQauihWEhMD/mHvLaqHG' .
		'lBnBLID1kEi4CV6zjS2xZzjrivSlZAZZiU2ES7ElrfV6ApPzp6l50XXyoc56F1XrnLR64TFYlsl8AgDvlVx/w685rI2H7ZLK' .
		'phmFs3TgoEOEQlTSmzyDeDBdmAuDwQDik2CAha3KqzqWE5FPpNmSRHN2nwKPHVOamgWM2S98lsmMNgDOkbaAi6yRqjSkhZAT' .
		'o9ufDXI6ngWaWVKPmPGWxi7KlaiU6wDZkFWAk6tqqYKziY8goxSsVXFQnT+ioFHKmD6h+C5JlNSbiwDbj0iAlKp3qQHDVI5X' .
		'ooO6W3CXnLOy8lE6l7quFS3slFKfDQDejoaAlpoxvt15T3/GtsN5BmSIrzZ5SkcqqJJ6FyQtpWN7DgAAj5mAAJj5vXmGFn8N' .
		'tUiES2PTraWC60ZzpvSCbyPKo3yC7wAAjtWAAJguvBWS/H4qs+6PlGLwrECM70W2pY+LWiN2oeKK7wAAjiGAAJdfuxSct31p' .
		'st6Y+GI1qyyULEUipGaRdyM8oI2P5AAAjZaAAJa6ukGtwnzrsfqnbGHNqkaihUTSo4Of0SMhn2qWQwAAjTmAAJYKubC8RHyK' .
		'sUW0KGF+qYCuUkSfosaqNCMmnrOXxAAAjOaAAIS00J9482y+xs14a1PLvcl4kDiQtfF5Pxbysp56UQAAifmAAIRUzvaE1Wxm' .
		'xVCC2lNpvBiBqDhLtACBXRdJsAiCCgAAiiWAAIRDzU6Qk2w4w6SNa1Mwuk+LKTg0siOKOhe0rcSJKQAAikmAAIQjy/ucm2w3' .
		'wimYOFM8uMSVFThVsIWUBBgqq+qQmgAAimWAAIPwyvKo8mxCwQCjNlNut4yfZDiWr1Cd6hieqniQpQAAinmAAIO+yhK1xWxY' .
		'wA2uiFO1toqqGDj6rkylIRkmqWORAQAAioiAAOsVeF91rMaPeWd2kqCbemp3mnj0e3V42k49fJ16LhhnfjR7K+fcdZaH3sPx' .
		'duaF6J5YeC6EZXb0eXyDRExkesOBZhYLe6aCYOT1c4KaOMEudOyVl5vodl2RdHTod92N+EqueTaK5RP8eYWHxOKLcgWsn77Q' .
		'c3CljJmxdO6e93L7doaZE0kmd+eUNBJed9yL8eDCcQu/NL0Mcmm1yJf0c+Cs0XF/dYek00fqduGebBEkdqiLl9+BcHjSIbvD' .
		'ccDGXpaScyC7JnBEdMixZkbrdhyo3xA7dcqK+dFzhodze7FXhWV00480hIh2KGrUg+13pkLLg9J5LA1EhaF6ps9KhBWELK+I' .
		'gyaC/I1mgmyCD2kXgfaBb0EsgfKBAwvygy+Au8zmgiCU+q0LgUSRdosqgKuOPGc8gGiLjz+rgHCJewqggSaFqcrMgLWl5ar9' .
		'f96gRIk7f1Ca4GWafxmWHj5qfyeSzgm1f5OGkMlCf8K3JqlvfuivbYewflKn52RNfiihZD1ofiicigkEfmmGGMg0fyrJF6hC' .
		'fki/QYZqfZe1oGM2fW+tyDyYfXGk3Qh4fY+FuritlWpx5Jwikj1zYn1yj4h03Vw2jUp2fzahjC14CAPui1h7Mbb/k0iBMZqJ' .
		'kECAe3vjjaJ/8FrHi35/vjVZil1/xAOAiOSASrVAkXWQYpipjoSNqXoui/CLN1lNieyJRzQviMyIJgMWhuqCFrONkCCgA5cE' .
		'jTObcHiBiq2XHVfkiKyTcjMlh4eRUgLBhV+B3bJJjz6v2ZW5jFOpe3c9icSjXlbRh8meZTJghoSZ6gKJhDuBtrFmjqfAbpS7' .
		'i7y4SnYwiRywdVXshx6q0DG0hdmgLgJYg2iBlqEnpR9w6IdooCdyXmuym7pz4U1Ol/N1gimHlo12sQBCiat+NKAAo05+OoZa' .
		'nl59ymqfmet+RExMlil+cii7lJZ+dAA8h9p/+Z6zoaOMw4TmnMKKi2lFmFSIoUsolKOHZCfykvWG4AAyhkqAIp2EoFSa4oOt' .
		'm36XGWgNlw2Tpkoxk0iRACdYkXuPdgAuhSOAH5yKn3ypdoLDmqCkIWcXliCfM0lYkmGb0CbSkGSWKwAohD6AG5vTnvK4qYIA' .
		'mg2x2WZNlXyrpUivkbinqyZfj7CZ7QAkg5iAGIsUtdpwE3OXr1NxYVpfqVVy0T5ZpEN0ThvcovB1FAAAhEyAAIp4tD19anMZ' .
		'rZZ8/1nZp2t8UT3koj58lxvLoG19BgAAg7uAAInAsoiJkXI1q+mH4lj/pbmGkD04oI2FrhuWnmiFFwAAgyWAAIj3sUGWeXFq' .
		'qqCRiVgypGWQjDyinx6O5RtunK2MUQAAgsKAAIhlsFijl3Deqa6fKleno2SbaTw0nhCZahtcmzyQ0AAAgoyAAIfhr9CxlXCC' .
		'qQSr4Vc8opCnLzvdnTGjxxtEmlGScAAAglqAAHYvx6VvUWCJv9ZwVUlduIRxki8lspxyvA5ysAxz1wAAgUKAAHYlxgl7Z2CR' .
		'vfx64ElftlN6jC9PsBt68g8QrQZ7lQAAgUGAAHYaxBmG4WBRvAyFE0kgtE6D2y9DrfmDfw+YqlqDDwAAgUCAAHXowpmSgGAs' .
		'unCPu0j5sp2NfC9IrCKNCBARqB+IWAAAgT+AAHW0wZmef2AkuUGaxkj6sVCYAS9aqsWXEBB4pmKLIgAAgT+AAHWKwOyrBmAs' .
		'uF2mWEkMsEmjFi9/qa+euBDJpSmLWQAAgT6AANc6cH1rYrYwcrduCZNRdL9wkm5PdqhzEkXyeG51YxEpegR2FdQlbRl9PbOu' .
		'b699E5EpcgZ9G2xudDp9UkRDdhF9dg9zd2R809FMamOPErDobSGMUY69b6+J2Gpvch+H10Ktc/yF7A4MdUGDas7waHCg4a6U' .
		'az6b2IyCbeGW/2iPcG6SoEFAckmOXAzuc5qILM1KZxuyuKzpaeWriorTbISkZGcYbyGd+UAecOiZAAwgcmCIM8xDZlXEs6vJ' .
		'aQu7Z4mGa4uyE2Xobiep8j8lcR2jAguGcZaHyr8zff1pzKJYfihsy4L0fnVvfGDgfuNyFDq1f6F0Vwb6gYd16L07evN6ZaB5' .
		'e3J67YEme/F7Ql9GfIh70jlXfT18aAY4fvB8obrneGiKk54HeQqI2H73ebSHMl13eoCFyjfseyyEkwV6fUuB37jldpSbBpwH' .
		'd0WXRX0Pd/+TelvjeNyQEDa9eXqNvgTwe+aDS7d6dU6roJqMdf+l53uWdrSgElqed5+a+DXOeB2XNgSLewuDEraXdIy8mpl8' .
		'dS20+XppdcatKlmUdq+mwzT3dxufWwQ6ekuC26gKjGZomI6dioFrp3JUiRduYlMFiA1w+C6yiDJy6AAAhgF3w6aYiaV3joz8' .
		'iBV4cXC7hsd5RFGlhdF6Ky2Thcd6bwAAg8t9c6TkhzqGW4sehdCFZm8YhJyEV1Bdg76Dhiyjg5WC+AAAgfKAAKMzhYGVnYmH' .
		'hCGS3G12gveP8078giWNbSuogd6L/AAAgFCAAKIDhFOk34hFgvmgbWw0gcKbwk3lgPOX8irygHWUcAAAgACAAKFCg5i0rYdR' .
		'gjuum2spgOGoPE0BgAqjtCpKf3iarwAAgACAAJG8m6xnwXrCmBlqrmEilRxtWkRYkrlvzSE6kwZxAwAAgJ59F5CymVV1dXmh' .
		'lfF2cWAHkut3yUNokIB4wCCjkFp40AAAgFN/oI9klxiDEHggk9GCkl6vkNqCA0JVjoKBvCABjgqA6wAAgACAAI4tlWyQwnbi' .
		'kjaOql14jz2Mh0F1jNKK3h+IjBKJtQAAgACAAI0+lFCezHX+kRqbTFyBjgqXtECSi56VRh8Pio2R4wAAgACAAIyfk6GtO3VA' .
		'kGKod1u2jTOjoD/jiriguB6biX+UkgAAgACAAHyrrApmvmd+pydpb1ALoslr9TU5n3huGBOlntBvFAAAgACAAHv5qhh04Gbm' .
		'pRh1jE+EoIB1XjTinP92WBO7m8F3CQAAgACAAHsqp+R//WXqovR/VU6nnl5/DDRDmtd+7hOfmSd/JgAAgACAAHpXpkSMtmUW' .
		'oVmLO03RnLeJNDO0mRGIWxOHlvaGegAAgACAAHnCpR6Y7mSCoCyWSU02m3eTwDM6l72SiBODlTCLOgAAgACAAHlQpHemNmQz' .
		'n2OipUzJmnafLjLclqKcwxNik/WNGwAAgACAAGh/vbVleFSOt/1nyT7OspVqBCU5ryBrhQfJqLluZwAAgACAAGgTvBFxOFR1' .
		'tdxx+T7dr/1y7yWWrBRznghHpa52DQAAgACAAGfOub98gFQOs4V8Iz6SrZF8ByWYqXN8FwiVowp9AwAAgACAAGeEt/OH7VPX' .
		'saCGuj5Tq5eFnSWXp0WFmwjSoOGGGAAAgACAAGdHtsGTxVPLsDWRuj5BqgyP5CWWpZ2PfAkEny+GGQAAgACAAGcitgWfdlPa' .
		'ryqc9z5CqNia5CWipFGXzwkcne2GKQAAgACAAMOGaCdhMqWPa2JlZIWxbldpVmNrcRptFz11c05waQrFdpJxm8B4Y8Ryn6Ml' .
		'Z390IIOfat11pGGhbfd3KjvtcCN4bAnQc+N4xb2WYDiDtqBlZDyC1IFCZ+OCBF+ya0WBUDp1bV6A3Aj8cjZ/tbssXXSVAJ4c' .
		'YaeR3H8dZXeOv133aQCL3jkwaviI5Ahics+FD7mCW5amMZyBX9ig732JY7Kbn1yYZ1CW4jgxaRuTbAfncvmFWLiEWmm3Vptz' .
		'XqGv7nxiYmWog1uMaQGiLDdUZ5uc+gePczmFHa1ddPJgMJM3dkpkmHZcd7BojFaOeR5sOTIyeoBvLQJXfm1yVqtVcPBwHJFb' .
		'csJyI3SkdG1z+1Ucdgx1ujERdzV3AAIrfcN5bqjwbZaAAo7mb6l/6HKBcZB/u1Ncc21/hy/CdGJ/SgHifV5/IKbkav6P7ozp' .
		'bTaN3XCkbz+LnFHlcTOJfS64cemIRQG8fUaBFqV3aTqf54t2a36b7m87bY2Xt1Cvb5CUEC3ncAqRewGZfU2BFKSZaBiwAopy' .
		'alKqKm4jbyqkEE+6bkufKi0dbpeZhQF7fX+BAJe5gptfa4CbghpjyGaDgfpnq0kZgiRrMCXgg0htagAAgA93IJYlfuht/H7p' .
		'ft5wR2Tufu9yVUfTfzF0MiT+f+B1KAAAgAB9AZRZe7981nz8e/d9K2NYfDF9dEadfJN9qyQ9fPF9ogAAgACAAJKgeVSLMHtg' .
		'eaqKGWG2efiIhEVQemSGyyNYeoeGMAAAgACAAJFrd6+Z0nokeBCW+mCEeFuTz0RHeMiRQCK8eK6OwwAAgACAAJCieF2ounk5' .
		'eIGkWl+QeJSfm0N1eMycWCIVd1OUtwAAgACAAILGkWNeom3Rj01iwVYMjcRmezq4jN1psBhzjchrHAAAgAB8SYGEjh9r62yS' .
		'jFxuQFTwitpx0znmieVzihgkijty7wAAgAB/hIAWiyh5JWsGiZl6wlOliDB7Ujjzh0Z7DBe9hzp7SwAAgACAAH6+iNeHMmnF' .
		'h2KGllJ/hfiFlzhMhPKFEReChKGEuQAAgACAAH2+h1OT6GjiheSR+1GDhGKP0Ddag1KOKxcbgq6MwwAAgACAAH0Ohk+homgd' .
		'hNyekVC7gzia/jatggyZKha/gT6PMAAAgACAAG6KoWddcVtAniRhPUVim3NktCuGmhhnVwvumAVpWgAAgAB/rm2FnqRppFpw' .
		'm2dr40TDmIFt/Cs+lt1viQwZlGlxQwAAgACAAGyEm8h1s1lNmKx2pEPTlc93dyqilBh4BQwTkUN5WgAAgACAAGuGmY+B3Vhc' .
		'loKBrULrk5qBSSoYkbSBDwwAjp2A1wAAgACAAGrRmAuPGlewlQCNxUI+kgOMACmMj/2LNAwBjIyF2AAAgACAAGpKlxKax1dT' .
		'k/OY0EHWkLqWdik9joSVCgvpiwiIDgAAgACAAFrZswpbn0iSryNe7zQRq79h8hp6q4djZwKTngRpwwAAgACAAFnysLxm6kgW' .
		'rHpo6TPfqKNqyhrQp7prhgMNmnhxeQAAgACAAFlord5x7Ud3qZ5y3TNxpbRzuRrNpHJz4QNbl1R3ZQAAgACAAFjhq4h9E0cQ' .
		'pz99LjMQozp9HRrMoZp9AgOWlKZ+vAAAgACAAFh/qfqIZEbmpYmH0jLpoWCHExq+n4iHOwPHkpuCPQAAgACAAFhCqO+T60bd' .
		'pEOS7DLgn+2RzBrOnd2PhQPekQeCnQAAgACAALCcXsNXSJVzYulc2XhjZs1iIli2amhnHDUBbJ9rZgWYdott+614WMdoLJMA' .
		'Xa1rPHZOYiNuMFb0ZipxATObaCRzWAU+dyR1e6qBU9p45pBFWUJ5m3QDXiV6T1UbYoF7DDJIZE17pATrd7B8uqf0T++JfY4I' .
		'VbqIMXIBWueG3FOQX3iFXjEyYReEegSreBuCaaYdTPSZ9Yx3UwSWY3ChWGKS31JiXRKP0TBnXpSN3gSAeGWDC6UBSxuqSYtv' .
		'UT2khG+oVp6fAFGNW1aafS+1XLSXBgRaeKWC8ZxqasBW3IRrbUxccWnfb81hlEw/ci5mTSlxc95p0ACFfyBwwpoGZTRmQ4J4' .
		'aHBpqGgza1xsyUrrbf1vpiiIb3txmwB5fzR3+Jd7YIh1w3/6ZDR3FmYbZ3x4N0k+amF5Pidea7Z5zwBlf1V955VVXNiFdH3+' .
		'YNCFLWROZFaD6kflZ1iDByZ7aKCCqABaf2iAFZPOWg2UZnyRXjOR9WL9YduPS0bHZOqNEiXVZiiLnABSf3aAN5LdWEqjt3uS' .
		'XHufcGH/YBya90XxYyiXkCUmZF6TlwBKf4SAMohTd6RWd3L5eJhb4FrIecVgzz8Yew5lLxzkfO9nsQAAgAB2mYZWcnVkhnEn' .
		'dAloDFk3dYRrPj3oduhuChw/eINvbwAAgAB8rYRTbg1ymW8icAh0WVemccR12DzDc0V3FxuxdL53owAAgACAAIJ3apWBC213' .
		'bM2CI1YEbrOAnjuGcDeAjxr0ccqBjAAAgACAAIEkZ/KOsWwzak+Ms1TWbEaLfjqHbbuKNBqAb4KJUwAAgACAAIBHZj6dC2tJ' .
		'aKeaHFPuapKWxDnEa/GUrRntbfSOtAAAgACAAHRmhbxVzmEJhU1a6ErhhUdfjzDFhdNjZA/fhx9lcAAAgAB7pXK3gQJimF+T' .
		'gRFmGEmugSxpQS/3gZlryQ+xgsJtNwAAgAB/T3EOfOBvd13cfUBxc0hJfYdzKC7zfet0aw9Wf2p1fgAAgACAAG+KeZV8WFxy' .
		'eip88UcJeoB9OC5Cerl9Yw8qfJ99zwAAgACAAG5fdxeKylt1d8+KCkYTeByJAC1eeCaH9Q7eeoyHZgAAgACAAG2VeVmWbFqq' .
		'eXeUnkVUdnOSVyy+dk6RgQ6JeOGJVwAAgACAAGDdlXFUUk8Yk9pZCDqFkvBdRCDqlAVgBQXfjkhk8gAAgAB/TF9tkTdgAU3q' .
		'j+pjYTmajuhmXSCDj3ZoHQYHipBsxwAAgACAAF4kjUZrwkyPjDJt2DiIiz9vnB/ai39wbwX+hzx0NAAAgACAAFztih56VUtx' .
		'iTN4kjeFiDl5Jx9biA57bgX2hHV7dgAAgACAAFv2h6yDb0qNhueDVDa6hd6C6h7KhUiC0QYLgpWBiAAAgACAAFtHhhmPvUoe' .
		'hWmO2jZshB2NYB7AgxiMqgYIgUKEFAAAgACAAE1UpzRR7jzhpOVWHSlmo7JZqw+7pfVbHgAAj8Bm6AAAgACAAEwAo4RcszvD' .
		'oVNfxyiLn85iVA+zoOpjIwAAjTpuXgAAgACAAEsmn55nazrGnYxpcSfKm/lrBA+LnFBrUwAAiu1xTAAAgACAAEpNnGdyVjoK' .
		'mndzdSc0mLx0Gg+FmFV0NAAhiNx4igAAgACAAEmVmfJ9bTmEmB99yCbTljx9sg9flUB98AA/h0d/kgAAgACAAEkqmE2I2Dk6' .
		'ln2IqCatlGuIHg9ykwGGrwBQhiGANgAAgACAAJ77U9VN1YZSWORUomvRXcJbLE6HYi9hUSzQZBFmfAF7fYBrGZujS5xeKIO6' .
		'UcFiq2mjV3Fm/Ey6XF5rCSt/Xm1uXQGUfVVy2piIRG5uaID7S3twqWdoUdJy1kr8VyF06CpZWYR2vAGofTJ6V5W7Pl9/J37H' .
		'RkR/JWWXTS1+30mzUsJ+9ymAVYF/AgG4fReAOZObOZCNw303QiqL9mRtSZOKjki8T1GJISjvUluIkwHEfQSBMpIzNg+dtHwp' .
		'PyKZhmOyRuGV6UgzTLqTMCiCT/KRXQHNfPWBOIysXw1OAHZSYsJUiF3ZZmFaxUJEaZRgfiDcbBZkgwAAgABwJommV0VcznQr' .
		'XANha1wnYEllwUD2Y8JprSAaZntsRQAAgAB3Z4bZUGNr1nGXVepudVoSWsFw5D9VXnlzEh8XYbR0bAAAgAB9cISDSqt6uG+W' .
		'UM97dlhXVhp8DT4jWe18kR5rXmd9LwAAgAB/yoLRRi+JgW4oTMyIb1chUmyHNj0kVjiGVR34XAqF/AAAgACAAIG0QueX420r' .
		'SdeVEVZIT6SSKjx5U12QOB16Wq2N2QAAgACAAHo7aw1OCWXvbYBUM09eb/haETVCciJfOhRZdXJiPwAAgAB2MHdxY7dbfWPg' .
		'ZwlgCk2/aftkRzQNbCxn6BPUcGhp9QAAgAB8WHUJXSxpHWGvYSlr90wnZIZuljLxZq5wwBNxbPNyFwAAgACAAHLzV7R231/q' .
		'XC94F0qBX9p5EDHDYdl53xLhaoh65AAAgACAAHF4U2WEk16bWDuEOklXXBaDjTDPXcWDIxKUaKqC4wAAgACAAHB8UC+R1l2v' .
		'VUiQL0h7WTCOIjAcWouNIxIgZ3yI+AAAgACAAGdGeFRNdVToeaFTP0ALexxYrCbSfNFc8wjpf0ZgyAAAgAB7ImTVcYdZp1MZ' .
		'c35eGD6ndTBiJCXndn5lLAjBe6FoewAAgAB/GGLCa1BmHlEibctpFz0db7xrviTNcJ5tlAh3eLRwrQAAgACAAGDyZgxynE+C' .
		'aOx0Lzu/awB1dCQSa1J2SwhbdmV4pgAAgACAAF+SYc6CQ05hZQyBjTq8Zy6BtiMqZx9/kAgodK9/3AAAgACAAF6hXpOLsU2B' .
		'YhCK6DnxZDCJriKJY/GJkwflc4WEcwAAgACAAFQlh3dLw0Obh7JRMDANiJRWBBaEizlYygGZhRxhwQAAgAB+3FINgS9W5UHg' .
		'gfRbLC6mgsle2RXShE1guAGjgdppfAAAgACAAFBVezxiR0AofGFlSy1HfUBnzBT4fhlo0gGaf09umAAAgACAAE7FdhFydz60' .
		'd5J07iwEeGp2JRRTeQVxagGTfdV1uQAAgACAAE2Dcc55ZD2Mc7N6HisNdH56eRO3dT56uwGkfTl+rAAAgACAAEyebn2FQzzY' .
		'cMSFFCqZcXaEexOCclaESQGOfWCBDQAAgACAAECZmUVIqzGpmJ5NqB6gmeRRcwZamjxT9wAAgtFmJQAAgACAAD6jk4xS4y+X' .
		'k4tW1xy2lKBZqAW3k0dbywAAgGdtxwAAgACAAD1Ejc5dUC4Djh1gLBthjwJiBwVOjR1j3wAAgAB1PAAAgACAADv0iJJoAyy/' .
		'iVBp1Bpgie5qzQUtiB5smAAAgAB7UAAAgACAADrYhDBy6Su/hW5zyxmuhdp0DgT6hGV2HgAAgAB/IgAAgACAADoNgKp+Jyr9' .
		'gmN+PRk5grZ+CgTxgbJ+1QAAgACAAAAAgACAAAAA//8AAP//AAD//w==';


	public static function adobeRgb(): string {
		return (string)\base64_decode(self::ADOBE_COMPAT, true);
	}


	public static function sGrey(): string {
		return (string)\base64_decode(self::SGREY, true);
	}


	public static function proPhoto(): string {
		return (string)\base64_decode(self::PROPHOTO, true);
	}


	public static function rec2020(): string {
		return (string)\base64_decode(self::REC2020, true);
	}


	/** CMYK print profile (CGATS001-compatible, LUT-based, 8464 bytes). */
	public static function cmyk(): string {
		return (string)\base64_decode(self::CGATS001_CMYK, true);
	}


	/**
	 * Replace every tone curve (rTRC, gTRC, bTRC, kTRC) of a profile.
	 *
	 * The new curve is written over the original tag data, padded with zeros;
	 * it must not be longer than the original tag.
	 */
	public static function withCurve(string $icc, string $curve): string {
		$count = \unpack('N', $icc, 128)[1];

		for ($i = 0; $i < $count; $i++) {
			$entry = 132 + 12 * $i;
			$signature = \substr($icc, $entry, 4);
			[, $offset, $size] = \array_values(\unpack('a4sig/Noffset/Nsize', $icc, $entry));

			if (\in_array($signature, ['rTRC', 'gTRC', 'bTRC', 'kTRC'], true) && \strlen($curve) <= $size) {
				$icc = \substr_replace($icc, \str_pad($curve, $size, "\x00"), $offset, $size);
			}
		}

		return $icc;
	}


	/** curveType with a single gamma value. */
	public static function gammaCurve(float $gamma): string {
		return 'curv' . "\x00\x00\x00\x00" . \pack('N', 1) . \pack('n', (int)\round($gamma * 256));
	}


	/** parametricCurveType of the IEC 61966-2.1 (sRGB) tone curve. */
	public static function srgbCurve(): string {
		$fixed = static fn(float $value): string => \pack('N', (int)\round($value * 65536));

		return 'para' . "\x00\x00\x00\x00" . \pack('n', 3) . "\x00\x00"
			. $fixed(2.4) . $fixed(1 / 1.055) . $fixed(0.055 / 1.055) . $fixed(1 / 12.92) . $fixed(0.04045);
	}


	/**
	 * Append a tag (e.g. an A2B0 LUT) to a profile, rebuilding the tag table.
	 */
	public static function withTag(string $icc, string $signature, string $data): string {
		$count = \unpack('N', $icc, 128)[1];
		$table = '';

		// Existing tag data moves 12 bytes back to make room for one entry.
		for ($i = 0; $i < $count; $i++) {
			[, $offset, $size] = \array_values(\unpack('a4sig/Noffset/Nsize', $icc, 132 + 12 * $i));
			$table .= \substr($icc, 132 + 12 * $i, 4) . \pack('NN', $offset + 12, $size);
		}

		$body = \substr($icc, 132 + 12 * $count);
		$body .= \str_repeat("\x00", (4 - \strlen($body) % 4) % 4);
		$offset = 132 + 12 * ($count + 1) + \strlen($body);
		$table .= $signature . \pack('NN', $offset, \strlen($data));
		$profile = \substr($icc, 0, 128) . \pack('N', $count + 1) . $table . $body . $data;

		return \substr_replace($profile, \pack('N', \strlen($profile)), 0, 4);
	}


	/** A minimal lut8Type tag (identity-sized, contents irrelevant for classification). */
	public static function lutTag(): string {
		return 'mft1' . "\x00\x00\x00\x00" . "\x03\x03\x02\x00" . \str_repeat("\x00", 36) . \str_repeat("\x00", 3 * 256 + 9 * 3 + 3 * 256);
	}


	/** Overwrite the header's data color space signature (e.g. "Lab "). */
	public static function withSpace(string $icc, string $space): string {
		return \substr_replace($icc, $space, 16, 4);
	}


}
