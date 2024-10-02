<?php

// error_reporting(E_ALL); ini_set('display_errors', 1); // uncomment this line for debugging

/**
* Project: PHPWavUtils: Classes for creating, reading, and manipulating WAV files in PHP<br />
* File: WavFile.php<br />
*
* Copyright (c) 2012 - 2014, Drew Phillips
* All rights reserved.
*
* Redistribution and use in source and binary forms, with or without modification,
* are permitted provided that the following conditions are met:
*
* - Redistributions of source code must retain the above copyright notice,
* this list of conditions and the following disclaimer.
* - Redistributions in binary form must reproduce the above copyright notice,
* this list of conditions and the following disclaimer in the documentation
* and/or other materials provided with the distribution.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
* AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
* LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
* CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
* SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
* INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
* CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
* ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
* Any modifications to the library should be indicated clearly in the source code
* to inform users that the changes are not a part of the original software.<br /><br />
*
* @copyright 2012 Drew Phillips
* @author Drew Phillips <drew@drew-phillips.com>
* @author Paul Voegler <http://www.voegler.eu/>
* @version 1.1 (Feb 2014)
* @package PHPWavUtils
* @license BSD License
*
* Changelog:
*
*   1.1 (02/8/2014)
*     - Add method setIgnoreChunkSizes() to allow reading of wav data with bogus chunk sizes set.
*       This allows streamed wav data to be processed where the chunk sizes were not known when
*       writing the header.  Instead calculates the chunk sizes automatically.
*     - Add simple volume filter to attenuate or amplify the audio signal.
*
*   1.0 (10/2/2012)
*     - Fix insertSilence() creating invalid block size
*
*   1.0 RC1 (4/20/2012)
*     - Initial release candidate
*     - Supports 8, 16, 24, 32 bit PCM, 32-bit IEEE FLOAT, Extensible Format
*     - Support for 18 channels of audio
*     - Ability to read an offset from a file to reduce memory footprint with large files
*     - Single-pass audio filter processing
*     - Highly accurate and efficient mix and normalization filters (http://www.voegler.eu/pub/audio/)
*     - Utility filters for degrading audio, and inserting silence
*
*   0.6 (4/12/2012)
*     - Support 8, 16, 24, 32 bit and PCM float (Paul Voegler)
*     - Add normalize filter, misc improvements and fixes (Paul Voegler)
*     - Normalize parameters to filter() to use filter constants as array indices
*     - Add option to mix filter to loop the target file if the source is longer
*
*   0.5 (4/3/2012)
*     - Fix binary pack routine (Paul Voegler)
*     - Add improved mixing function (Paul Voegler)
*
*/

class WavFile
{
    /*%******************************************************************************************%*/
    // Class constants

    /** @var int Filter flag for mixing two files */
    const FILTER_MIX       = 0x01;

    /** @var int Filter flag for normalizing audio data */
    const FILTER_NORMALIZE = 0x02;

    /** @var int Filter flag for degrading audio data */
    const FILTER_DEGRADE   = 0x04;

    /** @var int Filter flag for amplifying or attenuating audio data. */
    const FILTER_VOLUME    = 0x08;

    /** @var int Maximum number of channels */
    const MAX_CHANNEL = 18;

    /** @var int Maximum sample rate */
    const MAX_SAMPLERATE = 192000;

    /** Channel Locations for ChannelMask */
    const SPEAKER_DEFAULT               = 0x000000;
    const SPEAKER_FRONT_LEFT            = 0x000001;
    const SPEAKER_FRONT_RIGHT           = 0x000002;
    const SPEAKER_FRONT_CENTER          = 0x000004;
    const SPEAKER_LOW_FREQUENCY         = 0x000008;
    const SPEAKER_BACK_LEFT             = 0x000010;
    const SPEAKER_BACK_RIGHT            = 0x000020;
    const SPEAKER_FRONT_LEFT_OF_CENTER  = 0x000040;
    const SPEAKER_FRONT_RIGHT_OF_CENTER = 0x000080;
    const SPEAKER_BACK_CENTER           = 0x000100;
    const SPEAKER_SIDE_LEFT             = 0x000200;
    const SPEAKER_SIDE_RIGHT            = 0x000400;
    const SPEAKER_TOP_CENTER            = 0x000800;
    const SPEAKER_TOP_FRONT_LEFT        = 0x001000;
    const SPEAKER_TOP_FRONT_CENTER      = 0x002000;
    const SPEAKER_TOP_FRONT_RIGHT       = 0x004000;
    const SPEAKER_TOP_BACK_LEFT         = 0x008000;
    const SPEAKER_TOP_BACK_CENTER       = 0x010000;
    const SPEAKER_TOP_BACK_RIGHT        = 0x020000;
    const SPEAKER_ALL                   = 0x03FFFF;

    /** @var int PCM Audio Format */
    const WAVE_FORMAT_PCM           = 0x0001;

    /** @var int IEEE FLOAT Audio Format */
    const WAVE_FORMAT_IEEE_FLOAT    = 0x0003;

    /** @var int EXTENSIBLE Audio Format - actual audio format defined by SubFormat */
    const WAVE_FORMAT_EXTENSIBLE    = 0xFFFE;

    /** @var string PCM Audio Format SubType - LE hex representation of GUID {00000001-0000-0010-8000-00AA00389B71} */
    const WAVE_SUBFORMAT_PCM        = "0100000000001000800000aa00389b71";

    /** @var string IEEE FLOAT Audio Format SubType - LE hex representation of GUID {00000003-0000-0010-8000-00AA00389B71} */
    const WAVE_SUBFORMAT_IEEE_FLOAT = "0300000000001000800000aa00389b71";


    /*%******************************************************************************************%*/
    // Properties

    /** @var array Log base modifier lookup table for a given threshold (in 0.05 steps) used by normalizeSample.
     * Adjusts the slope (1st derivative) of the log function at the threshold to 1 for a smooth transition
     * from linear to logarithmic amplitude output. */
    protected static $LOOKUP_LOGBASE = array(
        2.513, 2.667, 2.841, 3.038, 3.262,
        3.520, 3.819, 4.171, 4.589, 5.093,
        5.711, 6.487, 7.483, 8.806, 10.634,
        13.302, 17.510, 24.970, 41.155, 96.088
    );

    /** @var int The actual physical file size */
    protected $_actualSize;

    /** @var int The size of the file in RIFF header */
    protected $_chunkSize;

    /** @var int The size of the "fmt " chunk */
    protected $_fmtChunkSize;

    /** @var int The size of the extended "fmt " data */
    protected $_fmtExtendedSize;

    /** @var int The size of the "fact" chunk */
    protected $_factChunkSize;

    /** @var int Size of the data chunk */
    protected $_dataSize;

    /** @var int Size of the data chunk in the opened wav file */
    protected $_dataSize_fp;

    /** @var int Does _dataSize really reflect strlen($_samples)? Case when a wav file is read with readData = false */
    protected $_dataSize_valid;

    /** @var int Starting offset of data chunk */
    protected $_dataOffset;

    /** @var int The audio format - WavFile::WAVE_FORMAT_* */
    protected $_audioFormat;

    /** @var int The audio subformat - WavFile::WAVE_SUBFORMAT_* */
    protected $_audioSubFormat;

    /** @var int Number of channels in the audio file */
    protected $_numChannels;

    /** @var int The channel mask */
    protected $_channelMask;

    /** @var int Samples per second */
    protected $_sampleRate;

    /** @var int Number of bits per sample */
    protected $_bitsPerSample;

    /** @var int Number of valid bits per sample */
    protected $_validBitsPerSample;

    /** @var int NumChannels * BitsPerSample/8 */
    protected $_blockAlign;

    /** @var int Number of sample blocks */
    protected $_numBlocks;

    /** @var int Bytes per second */
    protected $_byteRate;

    /** @var bool Ignore chunk sizes when reading wav data (useful when reading data from a stream where chunk sizes contain dummy values) */
    pro�Z`���KXKϊ�@�O}�Z~K���8��7 Z�Uȃ�u�!}%�]E%�FH�u�%x���})���%X@Ζ/q%<�m˶��Bw���o�|\�Ǣ6}4�+C� �<8��]`G�?l����G�jM�	�D�4����P���tI`��M�(u���@Y��U74`a�
2�k��Up�
P�1;Ѣ
R/8#X�{L/�Cx�N���2j �RlW�U��G���H�R�h�heV�eu�G�X��)��v�(9j0 .k��}D�
T���\�Χv@�A�`w(�����9�� ��&X� xq��$x�E�(�H0X$��9��ɂj�9�|4��L)�x����"!�m�=@2E	�u?k�76<4V%L�c�I���x	SjM4�T���o�$.�$$r{w��z�Vp��@Ș	8H'4 c!SE�+Q'��� x��e��tf������b���y�>l�����.�
�~�-��f�f����H)��v$>uf��P#{{�f�*.;���V�T3/���V����t/~�89���fM3�3�&�P�����c'�8��@�Pyi��C:Q�a�:(/c�d)���}*��E�i9�
��+}��/�G�GG�B�NC\�s,�u&u��$��+����d0��_���� +��Q�42,�l��a�R��^1����T#%P���
��������X�� ��fK&ū��Q$��t&3��;���؍<�v|^�c�,@Ȁ�����  ����Hgt��  .A�C0�`h�X��{lw3����j�& �$Sg�/`@�*�e�f+;�gQ��. 8c�Q���	��b�0��>:{�Q�y\k�bw~�"����b:�YB�U)It�?OJ�Dc��De���;Xu/t:��v�d]4(i��m'[,H�p�o+8H)� ����A�El�Q� ��|��B�u�x%�B�� :�B"p%#���� #W���	�$��jR2b�ŀ��[��[���Zj ;mB�όh�9 ���z5g����L4%�d�-�p� �ꇽ$8�RW-paɬh�^�O�ҋM���Uh�+��7Q	��"t,� ��N|ȇ2H(@`-ޱ�8<��b�@�)�d!0�O��&`��	�EZd�R�T<F	����M�AkX�-o0�F�N���k7 �oM 9����+�z�^)	��B�{T�R����
J..�q�V�}� ��,9k�dAB�u8�*��4a;�`�Rz�Zq"h�}�	^��Ҡ����Ԅ��� |�����Rv2vĂ�=tU�m����ɪ	�O���*\F�H�Wp? �Ȩ���Ć��fV]��a�<l�ޫ���8O���S ��<``��j �l��Gj�<Dmj�8v��3�r��D2V�-4ݰ�A�� �j ąX�=h��$�ň�U�Hd���<�Ű�|rI���9qu/%�$M����	�u?�`�h���m rd��W;�}')��=�)�Y��J��S�QD�E�-ؐ���j2��ldKY���mH�up�	ۋ0��T�v��R#j+�D5��T@��Z��j,xH��,�z�unY9d�{����+��j�:9B��jA����45�)���"�!api0�!q��b�aX8���ނ�Ն�tz
;\.�@N�h�{M�����D@��5��Ƞj 1�%_�
�EP�D,�D�O�`�B�����V�;r\�{!R��	�B8LBq�����D�-V�E"֣j�P+jWo'�B.W�����Ԟ鈅b��Cb$��| � ���� ����)�YHz
	�!h�#�Nƅ��A�āD@
[D2.�8T ���F` �w�
	� d��MIp�:UW$�H�<���H&lÄWr-��R�~*쁰���TT��&���)*���p�M�5�V����"Mv�<�� !`	C�	�	���-$ L.%wt�B��+r	����d+`���hD���u#Z�v���咢ERYmB=�忯0�|�,m�$@G
+�*ދ�	�/tM�PU)��H�QW
�F�F���vw� ��A[E�y��L���jp"!�E��\`��f�*[	���fE?jX4[ �A����K� �Vb x�dψ#��9R{H��7*�_:eP�`�㳀�fU �`�aGv�K�<��LD� �Č��=��b�g �� M$�5j!_`��j�D�B��bX�] L����W �uG:���t� �!7|�
�7dk�|F',��R����; O*(O���|�p�E<dB`��3^��	^�eФx�*�t����vT,��f@�W��.οJ�l�T&T�H�h�;�N T]5��ЦXU'�?Cr,�?1����]1��]%D��kp5�CP`t1d!zEsx�k ��a�+R�
xD1a	q-0�>a�Ra EV�)P��!J�^!��D�!�J�uTr`#��mT�HtmT{�	x�%D��d���T;��&��^�IU���j ̀`U�~RE @0R}��x� ����1��,�1��(�DA�!��|��^�	���	����
Q<m�v�X5t3�
&��c-4Ƅ/6|z�B���N4p~��
,�m"��p�_D��5�U��
Y]R��QPD	�s�6����R��Re>}�e?�D�0�QtoFЮ��0�Y��;�Q;�T���j"9�k��2�!��`�;:tp�<B� �	�|��Q'DDڰ��9'o�S��7o_�:�A
���� �Dv��:�)(��=�~��v�\�x�D�����������\-!?4�e%x��Qj �3��HR��ɂ$%D,��<�P���|��A1P=Hpa}=!Z`��"n 0n@¬l4 �w��b��9C�X z5+��D�T�C@X��  ��!�ըը�B^!�ը�d �.��
iEi� ,i 0�mj#��))4t�X(9��� �A�,��6���djQ Uw�<��j��� A0�Q~0!�`��@N�)�9�@̌�*rr'
V�ű���	��X��G�d�������	m�hȼU��|������ʼY�]d�#���u�{�dR���}\QjL�C�����DM��[4�1���4nL�ۓI���F��q[	�4���!+�   q �a@C"���W�K0<%4mEV]k� ���%������/�+��+m;��:���pދ�+5��'���ػ=��)I���"aI���lF싢I��
F�� �
kRb�E�Cx�$l%xPF؈]������"�����E�;���!!�5�PQI�PЅ����S�S	�L<^��ӕ�o��������!#v������!L��}��SR�C��h�����!�˧ @��I�`-X�e�uCH;eu@nCX��}�ܹ�QK�׌h���gޒ'm_�e�C8w�|,v�H�2�TF�v��0����v��Ж�5Ԗ�ݐ�H|�gr�K`��$�԰:� :��%��B����A5��=B�脶e�x���:d�6�I:�����B�.�P������|+P�����;
�+�Ƃ$+�}+�_��1Q��R�{��kU7&#�C�@� 	��Lamd�2�,p��̆��Ry�{-��｀�0�( �`��/0�X����0��6ԑ �_��,��u����v�<,��4�h� z���$t���j OR:f��iQ813��� �t)����l`�H�RH�:]��<j�;�y��v���\�D��$��0I�P������ȅ\r��8��ˠ��č��QnEғ�	�t^�B�^uy��l����d�����������ٱ��ӑ�j"!�$��'�| J�ܴ����Z��Ĝ�H�s�.3	`�@BV�[�[x�n��V�@�A���G�|���z4�$Q\R��䐔�[���Q(<�#�"]��I�؆L����#h/���/�I'��(z�~�j+�R�.P���"�/H���A<��h�3���8�8���#c�3��,C��(�xv;��>A�A� :�A3<�
�8�88ޘ�A�k	6������R�V�hd���bnQ�%_�6P<��9j�I!Tcر9�uV[9�u�0Y�;�b9u! ɣ_9�">��WA�I=2�B���ӹ	�/v�+��Bq������R����Q���1�*P����G��AWBR����!�k��$�Ax���pXc#��&�Ȅ��㜔��5�\��&,�%�+��4xۂF];��8��쌺�)T��7���\;�t{�BHc���8��dΈ�\Ȅ�+��ꄈ 7�|��B������b�3�2��3�� $�x�����b��
��U�;ƛ�Vc�p�t�t��rD�( x� ��P���O����rr����r�ݙ(hb�<`r�`��"����A�uB@�a�4Q`�"�l��[rl��l����dah5hѐ�a@��B �`��k� �iҋJ�L@��D_�Dƅ�t�C2a\Ӵ�/�%
XpQs}�\�QФ�C��[R��T'��N�{T�i�{�T�Ba�D
`��R�%C <��kP�2P��{L��0�LL���`�%�pOH�ȕ0�DbD�H��%CR��P!���d"@@��q#<c�H�<k8a�A4j4k'�+8tl0���   �0MAƔ,0�,uDƔ{,�����mub�Y@��a����(�	L(Jr؊0í$0F$S$���%� ��p��+�F�b�s��2���º�R@T`�%D���sD�dUo����+�bR�KI�2��la���D�e:�;5�:��ސaH�{P,Éz	�@�`���	����x�"!�� Y� �t���<�Eo	t�1`q�a�AX�<=�#�谙}�j.�����QO������e�K�@�C �^F�~R�,St1��.�,�����B�p��|� qu���Ґ�x��1��@�8�@%���r��Ph�%'	��ND�Lj	���|�ӝ�v�d�ǉ �|"�`)@�x@Xh�K�,0�0�{VA���w-v	4�:��!|��N���ڐAz�j Q~��"���s�P������@Oy(�� �����B��@+[F����a���tI��H�sI5K��@ƔE�-d6Y?̏-Dj�p��j��U�[B���|IE|?����|�F�u2j ŝ��u��
D�����3^_�v�u����������a�\$��oD��%_�C`�u��`�p$+ԓP���¦+��
���K"�����*���4-�Dj ���ty r8���ҕ������tp�28�Ҕ�5��8 a�8?��o"\ #����O�� E}�A&@43Ā������3�؀��5�3Qp4=\b �?�?�'p���!?t=��*�?���`g�=?�#�d0�������� ���!�5��06��e=�3<�n
,�gP=X���?0�: =��D?�F=^�+�=>��:>� �=>��)�S>�>��9T>�>�&#����P��=� u{É�9�\$��tɐ�A����fd A��f䒳T���e 	d�fX�D'�h��k�u�e� ^�:p�X�0���>P��c�d �8P>@0�胍pԑѓ,)l�h����I�P(<��B4�{,��@�t��^lHx�Fj�ۆx�St0`zFv�/x�t4b K>!��od�6Ԛc �3�d:pFe��z��ȍ��>�����G Z�J
-�@�dQp̣HJQ�7�a<��:ka CGd
	�x9��S\�d�9��6�c���9��5�����RtUy=����d|�U.$)��`	�N��
��u]j@����A��7Y����Q}�x,� ztǅ�P@��P������=��$W	PV=x�@�!x��t$,�*\F?d䲾#L���x/�`u#,8���h���L�P�Ƹ��ٴ��`�}/$	u<$-����<����`�u�Ԧ��c3�d쒟���0��Q�u-,`G����$�l,t� �a5 5�K�͈]���Eb<�,��,����b����b	j	���9u#q ���H�"����*9<̐d*��,��R����c2 x|5|euR,����^h	�] (@h^/ɑGt�t�`S	�t�^l�p22pp("!z�<�� ��m�����$Dm �h��ސ#h�
�h�6��`�d�!#d��u����@\G��\G�l T|X5X	�|�A�(��?q  ��@��#G����b= 1YES��)r��kX6��o=X0�jFj\(bK� FD�ֽ@a��%��(��R6D�	ita@Ҽ'1� �q�� *�HP'�����LG	���T�@����nJ�P=F� "Un�\�^|���\�# -�Y�,ؽ���QI���$�ne$��nx�^r�U�k�@C��n�{V�� �F�S�YE/E�f~0�VP��4 ��<�f 
��W��c�)��Qj�U��'�dSL���ِ|t�s��ݒv"�(dx�܍��u�+ͩ"^���{^߆3�{X_������f����3b��>��GT3
�3H*z����PF��
�{����uL�g`c��u^��]^�`�H=����;�';D�����_k>���l�z-i��E���atM��U��I���3�wID$����A�Q<��t�LjId�F��5 щ��U�P� _�Aq��� �ۈ�"=G|�lVQ��#Q�\��\�k 1�+a�H��R+�k�+4�m�a�P+�@ ;�}c�[( ��8(�982}/���``(	v����������W3����#� ���8�	�I�p�*�)?��;��,���@6=�;�({	��5b=�j���F]����`��9�C��,�G�B����iQѱ��Ag�_?�n��d�-#��������<�3j�c��H�C<$׍Ex	ȕ8�g�X�`H��.Ql6Ma#d����f]!�δ�-%@@���`��u��f�'�����BDmH`��B�E H�Y%��(%!����qW[ }6��O&�z�HT�SY���$U����AE@yj�3Q�P8�sޤ: 2<֒��ō� Ǥc'5BQ��Uvj�d�1R	�`���d�B��e �9�YAX��A�ZE%��:P �"�����`0�Ap��a�`�H�j-���H�x���bu�@Wq*؄@m � �͂K��Ҝ;Am^P$٭"2ڄ
�"/.ܝj�kxhNX�vC/��Ę�e�d$�k �v0Dp$6�2�� �#�+C��TQ�u�S�L}=MZ ��"��Xe�?�'_�`�Q<V��8PE6!g,��l��w�^�<Q�����BGu��m��M��n� �J�|OHx!�V؆а4uP�2��SVۍ�U��\�.�63����tȁ}�jrG�{"�Ȑ�ì�n��6S�EB 5� �-z�)��?�����!�;B��؃���n�Kr�C5�iM�Qr�E&���[���!5֬�����f�r�;�s-A$�,�kz+�0)�h{��k�����,��]rIJ&3�&̃@k�V3&�k���	�E����on�$2��ԍ��A�HM܋e���
�ܥ�u��+�N��5!�	��k��]���H���E�%`u������XX�OuY�������S �uY�uIxHum5��QI �D�ܴ�N@;��0�"��ح��E�_zP��MJ�6������}�;sYl@�q<��J+HF�E��NF<�<�Q�`U0�jɠ8 Oj�@1�
��Y�!&�@iA��ưg{� 8*x%��v��� :�J,�{��t��;�sF*�xB�-9p<`�z��v+BB%Tף�Or�,F=6�yNhB8`"���J6"���'!P��x"z�`�}D2�:@!X �.���~U�X1�����������
E�;�m�t0�Z�~�DڻuK� ��l���dId�Qj1��ұb����O���\K��f`kZ{;�R� v^�ڻ{@,^�l�`%��^�T^�w�`�XS�@��z	�@`�El!�@g����ö60�r��T��7�t0�[f���t��� 2ؕ\t\�0��1���Q�c!�����G�#�����������h�҉�X`�^%7X���QϞQb��S�B�ư��/�]H۰k�OE<A��Y�Ow�yF����РQ%ZY���H�w��w�@y����a`�w@�0�m7�+�S����4��B���E���`��g�`!�Mۨ�`�s��2�PP�W�����>R��T����tJÖp����>�P
��i�]���V��3"a��$lIsZ���6�a���V�0&�U�V��V��ض=��`�␗� �7��LL��+�R���+��o��w�;U�t&b�-�u��7��m/�̳�8e����C�'�|g�X�fE�
՛�c���9&t>���C�uA�FXГ���_^��jFO��0�`�'@��s6 �������K���f M��&"�M�Xci��mHv��p��*:[m;k�eT�,.cM�MK��@uL8X���{� u����,�e��g�D�[M�[C.hƊ� �D�R�!�[��ǂ�[��L��-� �s�k�e{�U\U���\�aql%[E�Jg+���[�U��c[��T��-[0��m ���>;�^�E��L@S, �֓��0�IP��Ŵ�9��v&`Ͱ��7�o
4��M�C��Z,,2U�<|�M��S3���r�S�0�a�U�1F8���6�Ja���w��t�8���E�-��ܤ���x����13����#p���/�a������`�e���b-�U��,�9��x����3���n���X��U�b4��ܵ)
 ?��0��b �d�ˊ�u����ųFT'��x��rJi0+�% B�ou{UP
�<ar0 ��<zw, �B��4@rX�߈����C�Q����ԁ��Kt��^�	B�	>���6"�F=�jQSIl!#^P�����_�j� J�u=b�'��X��ǅQ�	P�ƈ<�b��^�H%u����k%�x��c��+����x  �#��t��|�*vw����:wC�OЋ�*�-�������R�(̿��8���H�!�w��N  KuL���T��#���"#��+��+���*��#�lh>l�&�LT"D��HH�D|���z�a? ���� u�9��u+C4��l�R	���ȉ�j$�o`c�^�t@$��v@�)*j�2K�e¶1��[�s� �� � ��B���}c�.q [�ۅ,����q��/��#��N�f:%�A|<=t[N܁}H�NȨ$�P���� �   �� D�
;N�Ů��"w<�ŘC@�gkx�INl�Q-p�@�i��N<Gq���/����&M!0�1 �� x�c_�I{)%(���Eي�	y�>PN�b�l }>!��1�6�C �ńM�N�����6 �oCt��B� �c�M�(�`OY��M �
�`��\`�>VB��Au54����B�
``��>��C=�ZM���@M� ���p7�D�:#F�|�t�	�k�ihNH$�ő,H��s7�b �w�:L��3��"5<Y7������hS��]���������<�Q���D(��$v �eMn�lWRe,�U�٨De"P��5�dw.v��$t]��L������c6��+�L���<�GL�jQ_"���.�j�j{)�֚E��  ���� ���r$E�� Pdl�-�~�o�໖M���T2�v�ZTQ��H��{ ��aL���2Q��d�Q���?���4ƪn4xT�0,4F;>��N�~�� ����()�OL���̪��������[hGA|����2�7�j1\	X,�!�{�Ǎ��}����Vbr6i�=�9{;���޵���^�*�������_3O�W�=����;��coP
[�R���j��@�����Q��@��P|ه����� <|�i(�|5R��1�PV�E-���ލ"}�=E�#L 6�ܼ�@H�"� �ZD[ P�XD��r� m@(������3�5V}[������!"`g��X"C)��_�W�������E��ُ�b1�;�sr˄#5�+�x ui:�ıS�+��L��x�=~8$��u8�����c��.�م/���(Zto����$�v�Wm0S�&@����,�`��zK싕x�xT�~A6��8"c�)5䋅�8cA�x5�c���y_8ܕ�fX�n ���q� �4v{0i�t���6 �S�� vl8���"�:���l/F��"Il�8 v���a@�q�� �;;q08�q`��ӍIi�xv2|�t�� �$[������,u �!{�7JT��軐��L�BD(q� d<4d@B,B� $ d@	B�� d@(J�-�\r�J�J�Dq�Ȁ���Aċ��P�x�4 �S��d��
�Ŵ��q�ϱ��E����t�l�x��Fɳ`��)�y�j$����B`u�x�L�!���E�t����{r��%��H:�����x�t-���V9�T��*|��`t8J`(X����ǕD������k l� �`�*|'��5%��P6@DL )PB�-��+���e�}���}�������0��@q��0w�c\6��ۀ�v@{
y8	����
�<	�
�I� fPӺ���5�G�(&)	�,*�l�蝨w~���*�$`�G�Htl�R��*�7X��`���2���_�Pj]D�w`�W֍��z?�f�o0)(G�\Ff�싅X>ZR��)�p���/�Y)�h�;��%�Q)��s��$����T#�8&ՠE�J�~�%���*%D� ���C�(̇��rAS�� u�;Q4w���hH4Q	,/౦+M���AwO;B�M
Q���� �.Fu�`9��+/�Jܨ�xx8 hs?W3r<Qn�8{���xA<k2�[wl0c)�Au�}�pD!�.E�`�d%Xз6�Ԧ,��5��
��(�P4�5l���-*���ބ;(z�B��R�Q�__� �Qj�a�#�n(z ����-����6��Q�n��H��B�h� !ccF��OX�bp��4Dw�� ��	:��4�Eg|a�H0s�+� , ���ՙ
W��+��q(�I���V��	�L|��$�U����o�}��w���O�
	����� i� ��܉H5��0n�+�� ~Et��M�0���zB4M?�OPQ�P�:*r��>uA����@p������ȋy@�*���p�݀�O
��b�����aW�[8Q�P����(���ׄ�;E���0��� �[5"��P���)(DG^�g���3��E�^��HE�����Gq��3���B�5>�#�U�"�T��W�W�������Y��+�Cڨ�>� ����1"�.P8�;B�&�,�>��@��P@B _�/%@��(�� S��e�O΍�>Y Kr�,AwP4�	@^P�B8��}!VA���]�)���]��x_k����xE�M�}A�B�D�0H���V�WU�1�H �gb���Q�+�hasOB�4�A��aaq4��=�E��E�vC������������.����o�\zE��U�Ƌ0���x���N���t&�Ɠ"UBZ8."�M ��f6a��f)t��>�9@�h�!d�+bV-$�P�+��+s���&+�*&p!
L���W��$��)�4��$. _-؏b;��E0�1x(t7�(B��b0���=I�f$,,سVԓ�#Y��Bao?�DR��?���H"�4r=��^`O0W��L��xm�W�sѡ��аM�A�B��	O��"�N��O�uF��%$�F�xI�$�M���������0��؆�	��"QUm��S�� �������YhE�HJ�A�(l��@x�R��M��eq*���6!�}�'��	� c~��`lE��~u�����*�I��)����|�B��v�4�4����(R;!\����(�� 4t[BB�� DQ	����H�+��3nQ�P������am�(��}�b�"Ra!�	�d^��`���ڥ9#�����r�����8�s�\n���\�F�( @�_~pM(��P$���T������<��1��BetT�9u! =��CuӪ��'j�	���\�E�$vB;H�J44�؀�UOz�!`@k�*g�	N]ß@��@Fu^=0�M��ph�j'GE�>�A$Qz$��$��+�3�@�g��_#�Q?�Z<(؇�$Re;�E*V�6.(;���FP,'��pHpQ8Tj7G�Aď��0�82�|���UQ)�Eh��n ӋB��"����j������98[�h�����$���X	Y;��4S%�L����䃯����V�<�ad���E8�P��"�ƣ��G��� ����Ƈ�ڤ����8���9�ZR�e�0+�g�p�b�b�����K��QЀ��ؒ)`SnlVݜE7��ܲ�"u�>�����jX��bN�U�8d����L�����=�%��	�P2�9�&���)�:��*��� �
9���x�(��$�9����v?"����F��L�;�tg�u. <MAҐ�P��6 �P>c@�L�%xp�k�?���k�� uo4UV��~�5{;!����U�Y��`~�G�
ۘ'�G~�	�!� ���A!ĈE�kR�%�~y���vت	���E�UŕA��}�w�"-|���'������9�_�/fl��~UyqD#B�Pm�J���@w�r��%�?���������w�	|+,/+�vu;$V�\��n�e����Y�njZ�3�GQ�m�B??�-���-�b����a<�uh��Sj �(�q��A9�ya��p�4���ƺ�R{�]5�Vc�tA��}��s+.��D����M� &<.h��qBQl�}�sQ��"�r�.v���,�#�^3zV���UӐ�W�u	BR#�	�qO�p#�@�d
Y�lg���N���9ah*m$�0;-�WB�Ȩ!YqS�m���������	8��]cs:��QY�T�!%$�0�� l�����"?u
�&���BujQ�7��>p{[��$��UR�	b�NM�9�x@������<���_�ᑕM���AȪI.�#c������u#��;�w����F���"�F�U!G��u�ۻI��T��ތ�f�v�R��jG�V��ȷX4ĥİ}�?��ٝ�G��7F5�Ǵ�-��}�-�d��E�<��(I��P!P�P8��<�P�����Q�A$$��g��}�p���A!@`�� �������Q��֠���DA$
�! �v�B����I=�<���t�U0� F4�ʠ#���+�����*����bS?u�ӌ��\_�!H�ƅՌ���Rlr��'��x�zƗ4t[dr��G�(QX�@+G^��WS���mTG����.�(�v���Ks�\�������� ���ٷ����qJj �
VD�$0)�	�^��"e*U8�dρ앇"^\���R�� $�5$�g��aP� f]D�5A-��Q���K?L��;�v��eP�#����7�%ʧ��u/^ ��y
=Pk��g'��:ǅ@�c���w�#CQڃ|z�ם �w׋�@��=;�F;1s*��Z��M �ġ�������t�|�Z�C�X4�(��^���;1v*�n��(*�1ǐG�Vh*Lg X��;������;N�a�s+�!+]#�� }
�-4몄,Ǟ���*D�����,�z���Ԓ��T]n ��A��u�U�bc��U�(�h��=&/9B�A���h��<F� DU)��N�@b��}����<gA2�#6��W�l씍 ,�6�3+�� _ńY0N�(���-���r���`O��X�?M,�AϘ��� Z��A1J�N���HF�1�I
����ƈ��;u�@p�=��M��������d�9D�#D�C��g������������+>��2�Ύ�;�vŉ~�L�e!����)v��-�+Q~�b�8���f��T0t9����^E�-�+/�J�}#Ɖ�(;xsb���Q��C��EH��M�,�AdM;��w�|}d�Π�����}(�d���v-�A�d�E�$���RV�f�bl�0-p}ޭ�N#U��'�*D��(���>�@�j�U��'㇆p�P+� ���A�	^>*�0�c���+�
Rl�d��:�S;b�nT�����D��|-T���,�9�Ho/prƄ��^n;�%���s�: "��� p��`� ��Uj?�(}j��.+��u�cA\�P�J+X�*��f/��N�+���YO�4]��1�����Fd� 0M���\�e��f��{2���O��K�+�s#QK�3�~��ε3�`���������#��Mx�;jt9�)�#E�辉�dQ߆S묲�ù��"
0��M&�J����Yh��� ��	*ު`�j�P�X(u��Qq�s{P� ��gʵU�9;؀N��(����;� �K/����9�n���c�BU,�E�B'�D�y�e+_�B�>�h <(�(C�Nғ]�� �e[ ���Q�h$�};����M�E�}ւ�e����h�Y3�~���6�(r�Q���,���۝�q P���p��|j O�L�Qե���2�mud¯۳v[c�܇��0cux��%�Q����\��Lt((�e@��3�/�8"�G݆J�G4�BBj?�Hnx�����F� ~�B}��� �%��b�!F;;&tz
�uU>��Rv�s1�k���C(�ؑ���������#I��
�9�է��uDń�7����=��!�����؋��Q`��Eo�C%\En�ڏ�v� ��[R����dYD�Ћ�.��A��s%��3�U&̒���� ��<	 �lU��;���0���9]p��;�OU���b�zE���a�����w+�/��m;(rU M��܀h����aU��S-�֝(�{�$��&;�bb�Z+����Η�';v=|+c3Dh���T�P 	��M���]Ռ� ��3��ߎR�G�@>������t}��Ѐ�5�䉰=�I_ah�h���0��!������s�A��!���:�c�.�U�]+����������C��H 0�r4���!rV���mU<&�
g>eH���~�|+BZ��VQ#s Q��6
-�wk��j�.;U9�+D*��9�l��Pp90
����(�����h8DC ��B����̀��sr�0��9�J&���H�T��RM��	��ԥr#�P��}�
�;���U� �4��U�H�c@9P)~�R����}�n,�hn«
gQ"zT�pW�l3J(�e�+� �@QX.�P|I�M��b�T�?ȕ�(Tc+�1r�@���F��U�;u��,&¿Q���Р$���
���!l�B�0x���n���s<��U���������+M��>����ٳ��,1�����\����C��E��$Q25)�\���  ^JG�rr�!!G�	
r�!G�edc�_�Փ���E0�HL	��o&D�4j��B5 ���}6�3���X��P-��<��@�_��A��	;pޅUtUC�O	 p�G\� $[O�Q�x����60?%�����@�KV���
�����&PB*H�P��G� _ox�5Y$xE\��(!l+�"�L�T�XE�Ǡ����]��҅/��R�P�8�P4�����;�u�8t��<����d�0�gAu
������ E[r@@C��2�%(�
$)�j�88�j*(@l�f>vsK��Y �� }�T;�����=|��~ v�Ü�ZoJ�bAW-N
�-qRj�������A�ہ�`��|�D�ه�B*� �$h��`	�;3,80�tb@C�Th�8����JB�P{� �M*i��Џ��)�h��'5�^D�T�F��֫��n��<����LjU�Qa2+|�]�]��+�t(� �
:R�	<�L��Eck�H��;��	hv(=�BX��A8BAi�	{2�z�E"�YⰝ*`C�b'u����6�����ƿ�z k��t(@ �w��B� V�@WPW ��"@�1��'g��x�M@��a��B�
&R�ult*�NB��\H��&"bB!Z�h
R0E$�0�C:����d���H�j�t3T��G�`3�-
�
3�Q��AB��F۾�M0�A������7�PX ,�C���̦��1�^4��uaT�e!E<��S#�t�`e�r�f{u��/a�*9�Rr���9�	%���� iK�
!@@��RI�I�V�%� 5N�!�3�c�G��m/��N�b�Ks����R����\Dq~�t�\Z�f�Sp$oS�w-�t� �A� �ꡀR� ):`?c1�5
�� ��uC��7�A��u�)"�!�
��!\�k�<���O j�56 ��Hv6@� )���n������j���h�\��uM���A��W�'8l��
%W��Ag���@�XA�9�C��4M;��%�3FQ:���J�&��w>�k��#��0��_,<BQ����,�*c�X0���1h~�R�b�$��ī�3�!�W���V�/'�Ӱf��R�sg�-+��&%vLٍ��#�]�Ot�}��h�S�$���]bQ4��AP����$�j�u�6�U�Q��`��?y=����,g?�f�^|��aJHvr/����EO]��5 �P�P%��<�Q��Ɛ����QL]���b&��;JvM��W{P+��M�p7A@��r�m�1$d�
"BE��C7%� �^_^�A�+"+t8R@ �C� «XVP��.�q�jR�<g ��J� �u��$!\�)F�!U��� X`jvx���&
�s̨�W�Ȱ%@ʚo��Z& R&8(E�L��ȉM&U�L&�v�\M�&�t4�p
f�K`w����q�$jhT���zc$gkP�lX��*=��;h\�Q�*�h~0�mVU� �@�T�J��h�����El;Mv3E�$:�����{k�a+GE�=g�u����������Ű�`��/$ :L����7t�b��rr��L���.��:~L�.x�D�)Pu;���U ɊQ�Ku+]=�����i;�u.B/��/��\&d���G�=#L1N�� .��>/���AORL&{����� t�$b8�fQ��="܇�\7�� 	Kq0L� "�Q�M����C6�E����F����M�S��A��;�;,6d�+�8��NٰSE���l4VȌ�H�B�τ;�sC�UѰs��+�t�f
d7���>H�>U��ZQU�� �V����"lmX�!�%*��3�H�+�@���� 0�+��/���L��,�}�y|S�4��H�T�s3��[ �R��U�@�@:����}ಟ��Rq
����Fj ��	ɼFP�N�P�����U��6=:@�����REI?pVW?�#3�����+x��j��LljP��A������a$���/�P"!��>�	"��}K4ƲciE�.2�L�.��� ���\����Ԭ�R��&�]��/5Q,U�-9H����씠U�� 9H�̬���Э��h��� ^� 
���;���E�S�0U��"���ڀ���v*��	�$�6�<�6c���+=c���`���A�h iE �MpU��M���i!���(V�P����u	��]F��� �UC@���l	�ؐk+��E������E�ڶ|$|v]�m�M([$��`�Pթā:	����!g�]�;l��ĹG�(�2`��=���V��=�k�7��u����0�Y��8�X��$ '
h7 ����EX��.�'�$C��1W�X<�`C4�$
M�����j��xR�(W/ȐM`$��S ��v��o�,�r$�!J��+����;������nH�QL�D..(�~AP���lsJ*�%�;���xdCb;�(0�F�����gA�P��o��hH��M� 1,/��R��ƃ2%ǃ�(`�~�/m/�.��	��@��|�)�eG��|U�.�,���dl9h���51��B4p�4���w4���,�����d#r"Zq��MrH<1bKd��#�u]@	��@D��5�����@H���lBh{��@��,x>�T� ��4�\b{���mC
��?O ��17�O��UzE@�&��w��b��R&��,!�#�A�:�K�jl�h��-�h @�
c߷ĉN�"�
J���EL1&U��:����/5!ƖQ-@�`X��My4�����PT�p@PKD$�Aаm�d
`m'4�RhQ.�}@가�����aݐE#,���;v	��
+@r�ö@[XD\��ŝ��L{<�aU�4�.$!6{|��0V 0��d'gS�l�4.W|�U|- а�86x�A7�<Q9ZH�r�4�\v�\�ٛji�K�z��͠�axX��N&@�QX�4	iX�b0,w!Y<J���hP`P��١Q`I첁Ĭ'�%��g<M�Uv+��X�;o4���
���d�J� @z0v!s=e�u�	�r�	�@g9�p�������s-����	u�1��ՋQX��}jQP����~���A�\+E�\k�!#�`22�~�;K�Ж*����m�H�+�=�J*�QfA����9',d(ԡ�.��X�|���	�zC4X�G&�mć�� B�#�!͠k7�1�8��^V�,_��`$�5��-[Ǖ\ĥ@0�TR�3 �w9�A+��1q�������!��@� �`()h	��t�ؠ�Ez|8��	$��][�>����l��,bfG��Ma����Ef�
E�M]����"�
p ��v#�f��  F�^��l�U
O��5�xs��y��6�1:6Y:�8�.(#h���m4�@����� ����6��Y��o9V!!M�0�\��G��0E�$HR���k�¢z ���`C��.E� ��|)��.5�|�#�=Q�J�g�x�K1�BŒ�.��A��ԑ4c Zt׵�
�c@��K���2��Z��,F%~ �;�]Da�A�ǂ�g�,��o��M�&9�9 ;�$(�������pC��n}P=p�+$;�}��,, ��A����ʄ]8�̨QK����O m��fU��R��J�����%}� � �dOM� �)�� ��&�M��d��@����`�t`tY�[hTm�7Ҝ�E���<�<�-���X^��аeJ����(�N��M܉u� ?��ڈ�� �)��cAF��hO\��L3�|��n�2|�C9l �I��Ƙ�mx��o@o��lE�/t�+�b��x��������Q�� ����R=O �vd34�� ��hfXl��ie  E��$���ĉ��X������Al*����h`�Qf�j� f�G�F���H���]����i�FL����I��/�B�m�`�-�P�l"EH��Q�wƅR�WJU���/h���pL��/F�z5����K���[z/�#:(F��1 o��B\�!A"#�����~�W0��@5���R��pQ7<����߉��� ��}z�����vtP%���:��V CǛ9�/�\�b�����!�e��X:`�(��\��pM v�L˽�T�&F�D	�H}5S�4:�Q�8JK�	�	����T4;YD tM�}��6�H�Q�I#�[,0΋�7Ƃ*��-j�X��Dﻰ���D�D�$SbD(�$iA�f�l�R��I�N#@l ���2N�u�AAN�x3�c�$(c��� ���Q�����C\>�V����bЕ:�(�t*r�d�f
�\� ����1��q�<��{�;�tP�Y+�6"�ĻK�P�^.Q0�"2��$bQp9��F��=S��(�SZ1,
�[��*FqdQʂA�+��� ?hBb)�d�X�w��4IXt%��bt0C��~$^�!x+@�� �X��=zL��,b�L�B�,��L�9a'�_}G+�\$^�.��n{}P����I��`/|����j,$1�d�{->�! ~� l�-�0`�	�-�7�C([�����X@)�6°%	�a|����� $/䜽��f�ب���@���6.�ܿ��]�0���c�R�/+��8cU�"/`�Fq�+B���{�ø��_X����"j�<��%2�Z���4[�(C��	 ��TŌ6L��}eU�T������4+���,Q3P�4$�Z�F�	���mܱ�:��$P����t�O��	�Ph��p�=I=�%w�!�����>%l��Q{1�]Ľ-�|�A{��m5����MvY�9�!X��j�T=�P90~|����f L�C�P,���m ������ ͒o#+�
��߽ }	2�I�f�%�>j<��<�s�J@�րb��F�-�/ uF=�
ă@����I��x���h�����12ľ�Hk"�Y�}�T���Q"��V���]6����@�������b@�`��J �'� ?HU0����a M�5�� �<�h&�x���3� $?h<��A��(�(8E`$��VU	4�%R��<�Jg�O��P:tь# LP+dCd���6H�
�-{�(j����0��� ��64Q��}Y��[,�,:���J�9��LV�+@$"��7rla��>���B��o^��\$?_C��/"6!�0��?h,���;2��N�480V*����`��5�`
c��Y����\��+��t�s=e��mD�P,`�~g/DI���,0+`�F��������GD$5?P�-`���^WSՍÈ$�y[���YH�WXY}��cGuj_`���  ����c\��LaW�/A-7�|$�3���i�q��tOu�F8���Kk���

u꿍��$_;��u�~��_�a���k���uĊA1�f}׮�t��/4�¯d�G��(��Ë������l5بu���>�P�u;j	�d��mz��e� �hjY�O]�V}h�Y�؆�OGơ�Q1��{,Y�QS?g��~�
��Ju�v�{�l�L�u��P��vVRH�E4��<#<Y�LWVj �L�Q�Xu�e�-��qQ�nu��n�r�X^-�s�+h���ȋ���?XP-ܠG�WVS���:L�HQ%�����xN�A�Z� �I�|{�&
�t!
�FG87�o\8�w��8�	��8���Iu�3�M�	�_��W�V��x��َIv��m,7�( ��l����jht�#5�����^�3ۋ���t#�twQP.抻S$v�	����Y;�y��`��urX8Tv�]�_K�/���[^_�_hU���-n[�!ABO���^���	���R�L���5��T&�I���Y���w"9I�R�L&�9_!��s��A�3���&�L �Ca��;�H�*(瑘k�806|�ĤmjJޑ�]�Z�3�p��d��Ij^�Yr�w.��@+��#�va]�u-V����X2^YuQ�!�0��$�P���"p���-I���ܮ�J~�j$(�\�5~��3���lV,$=B+�_����2�%ml���x��z�����}j���U(��Q�P��p� ����
rIW%\�ua�soY�B�=I������7�9�!�GA�bw�
�����uһ�g�~�Q¢@��J#^0�J��䫪fj��G�Y_]G SU�`� "�uD 1��(�!�M����m[!l�T$Yf��B����sǩtfUDu*bAl��g*�C\�w�.�K-�oB���ً�f;yr�m�gw�1r(���gw��:�[��q��< �� Dξ�M �E���Sn��E ���{r��E���؋�f;5552�!�555:2!񊆶;��:0\�f�5�m�a��LRw!�YC���H�
Y�����][%V�9akxc��u�E�RW���AxY�n
���������ߪm4Y��v�%�9�q`��k��H�!rQ�k�r*E���Jz�n �d��d��-��؋]�cqm�
���O�XY�/���ABT��:Q(Nyj ��5ĳ���\�@0�-�,.-[��ܐni��\z�~�O��PPPCРg�-L���@?*D��E���!%�e�0@!*԰p���O*@�ڠ�L-����-:W�P�ZT�:n���ȋ����Q��mkǁp{p��l��x4�e�7roP6�=x��9�����E�(e��{{�i�� �׉�m��-Y�֝!(��^=�ZW�E����0����0��e̲�4IX�2���!��az�Q8ݒ�f"� �W����M��p@�M�
p��ux��cg�NM4k �cf�"pNCjm��)�w!_L�x����|9���Bѕ;�ON��9Lw)�ڃ.�~����M�4G}�#S &F�1�Ws�};Gw��vGCw��,\A��Uq� �Q�]���x��2aV$K�Խ��t��&���-j�@d�5RB[pADX/q����t.;�$@�4�mCo4���H|��JD�}�+��Q��T��d�� �Fg`���ƾ��=�yhrQ�R9QKp�m4��Q���m�Z?�KCm�[kY[� n�0hXHd�vf������C��e�,�F�muP�R����V� ��k�u��JtF�ƹP
��%�F[/صd]�@+�K�T��#��PNV8W��\�k�6%T	�?���mj�]WЍA��"vk[�
����� �tW�9 OkB_\�sE���Xܖ��]�S��$V|� ZA�5�F1(���+�-�.f~E�����o#C�]`��e�������
"��9����������S�>�+[��ua���Z�u3Cܶ����Y\k`x���--���,?R�����C��t[���A{G�ña#8�a���-Xi��C��Þ&��Z���4B��Q  G��YEt ���Fк7�jèD}7-M�St�苶
���3��Bzk}�Mhr�yX��
V�Ҟ�m7���F@�f&�m�ȦmU ��{�
M!�0�t4$|�J���v�؂p��%^F6N ��f�Y�J�dK�m�N�­�5xU�[k�к�k"� ��f�aY(�Y:�-4�~ppS0#�����S���9�OCVW�F�%}�؁&݋�V+����	Y�Y8t3w/�o���n�+Њ:˷��}�8x@8�8���ʪ��?0,F�C�V�і~3���? t�΀|l���77��V��7\DC(2���lc|�~��.��I���i{�F?��D0`��l��@Sb�5��� #�+d����Y jx
ƾ�i*A���*��ۦn�t	7<
���;Cx�̓<��' w�C��^_[]��m���1��#�¯�;�t�/�."[�a�PV�W�.c7��S̺ x�j@:A:=â�%G��X/pmGM��G�ߨ�G���e
3� ���G�����8��F&��ſ���	3�F����f9Y�K�u	f��$�X-%]�lGT�V�R��1�'�Nԍa��Mz�U�e�ۀ8_F@��u^ Cz$]�E����c�'"�?C20XC00���ۋ��`�@�#b�4Q��D��e+*!s{�a�vm��%���EVU�kT�{��]^A�3x<%Sm���P�yVQ6�5�6%D��w��>��U;0�4�ƨF�롸�,b��\j�?]�[�8Ul�)�A�Ab�;�P$��Y��Њ����8	�����]��3���%y�	�Wy�躑�X�	����(,�"�-a�-h��wx�z�������5u��A\T��v�������������d=��_�!D���r;'wr;+�֨v	L���������}��f���̱��@��V�}��1YpaU�ɩȾ�I>���w����w�;�^���]�k�ۉ]������g�}�`�;E�Hw|�(�W����z[45⍗!�t^���H,8��Q�A��d��9K��])8{�,��vu>?�J|�s1'۪T,����e�AY�0(v(����,��2 [�H�0(���}���T.\ރ7��>pj�I��G@W��nU�M,O@�����~�������iҀ�y*��F(�_�3�Y�|+�Bð��v��B�w����T�Q�BQA}���zl�i�����(]�^��*|4��_Pt��9}����HU��6j+�CB{7�uz �_j1����W�t�pͼ�Z���V��Mk�<��a "p,���C��l�B��ztك7L������Atuo�!��;(�It%�)n���u�-Q��t ����/Ku�#����(c��+�:��qu�����:l.�*�����.���c�����~�дD�3���KV���t޿,E�x����5h� ��q[� Ѩ�	
�"�	h�X! S�U�����_�u�< �z���0XT���D�[��|gt<��{���}%\�-:��'�����)���X�@z5�X|rV��d������&h��M��j������d�>,��3�u,9Xd~���}?`�_�2@hld(�}:��Q�BL�x�B�N��B݄��>�	df&��ЀFi0�"MM��hCW|�/�9�F]�*p�m�N �3m*�V�LWPl{�#�Fy&4!"���E5[w��
�)�"�Qpq���tۺ��A� >�h��R��h��k2R�MJ��&�$�`�	�~� j ���a,�K�~%�J�`�_982�z��сH1`+�������"���u�`�e�m�V�q��>k\�r��������`s�^�0(��z)��<4�[t*�އ�_Áj�6�[�Jj:vO�hox;�s��K�p��^��xM (�3�;��
n�����ȱc�������G\�9�MH}�%��x��S��;�$r�_ |�pwL��ps��&�<��8�(��-�\H[���;�r�K��p��v�\RQ@h���m>9|uK�Z2p໵���-V#`�E��Op�č�b<X��;������V܋��YxY�*t20[걪xG�C���65���bG�[1�kv��@��X���aT���ȵ��f��-%��-��}�h[m��s\����u���b��y��QwH4uSh�*�X�w��*l�#� �"C�r�����l¨S�H=+SF�P@� �	āc�Z-&ufVHR&4�KOP��MW�����H����P9)e�3��.�j�F���Z{����v��	�ઇ�	u�dJ���UF�:4S�ԉ�#��Vb0��V�� h �.�D��V\����z� ��.�B����&�d�D���r�`������$��
B8�tѱQ[��~u��E������ŏ�
�����3�g����'Zo��3�h�H�ā�u%�������XuĻ�7�C"�B�8�t63�8W���t'O�)��4M���-�pN:�B�7��#A"�&�x܂�f�8MZMH<���p���H��@�F# N"�,8��m�qh��i�*n8 �|����.���ErbX 65$�g��#Dp+6�o�
z�(̅�����ۍ� 8�B<a|Vx�P-A8BAT}{j>��z��d�I��� ��Y|)���O[d�U`�}PBP�-��w>j,d��ķ`�0����9;8��ۈ�;�j
n��Ď�*�t�t�����E�e����Pp���Z�q�Fʋv�T1����s_�L�1�DPuh���	sh"Y�
si�1`t���eD���vV=W{C�'��@�o�~@&��D��؍p�Qm�n�h ��6����%�Ϥ
�v ��C;�ec�F|�D][�'�^j�"~RF��F?��Y�J��6r�Z0�嫯�| �v��Du*�%< <Ǩ�S�8�Hg�jm�0}�@ A������I+P���r H������z�#�Sԋ���-����+y����i�	o����DNI��@�P�!�;1�1�V�ہ�������~����?vU?Z�KS��o�KuL s����������!\�D�	u(�O�F!<!�J� �����s!Y
� ���_�SZ[:w���Z5RԄu��]v?�Ξ����+u���6�&�?0K^;�vp޻��;�C8;��;^�&��tc�q�q@��t��\������!t�L�b�k��1��K�����ͷDJ�e9[`I�Nu�mu����t��B��V�\���^1(.���67�@;u`�X��P�M���s%O�� �_u	�%mD�	�-a�;$f%U��#]�ꍄ�	��j�D0�_'���9��<�܀?�x�v4�5����H�����?SQ���,62[	P���9@���� W�HC�-��
H�yC�`��� ��iRŖ�������)���Ɓ?��x�8���(+�K�Q����Z�x�����s߅@;6<ڃm�H�.R�=�`�|<�cZ�])0eW�<�,ʭ�M�^���"��I�� }��K4Z
e��e�%����q���+�{���Ig�鍍�ߗs"�;#+���#��u��;]�r�uy��;ط̈́|&��uYs�t$sW{?)쐯��7&��X/�n�{��Z)t�oxY[*n[YC�8�ڶ�O�n���Íh+L�ό��lg��|�D�7�pUƅ���D�������N�^
u#9�t��ʀ@��`HLW�/���fj d_w|ыBW��G�J T_'��i+���N�?~�
�L���-�,�J!Ja���+\' ��|8R�����#\�D��u�%m�E�!��Ou�h�+���R!خ)6u,���`;�Jz+��y��Fsuy���5ӳ�|�"�:�YQ!�m��ȝd�:��6�R�})�� �b�m��ǰ���	;
�	|�v���/(�N� ){�a�����N�	�"�XjU' �3�mm�uU�"2��v+`��!�y>u�J c�<��H���o-t
\ �" 0VW$�n���u0\�PoP	f��<ta��~Y�=Dh�A�ҩ�E0�4��*���3�2Z$nF  �W���\���d�̃[{�Q�>�~�mbA�N�!hm�A��q����� �O�C��x?i��Z�0	(tł�@���ukx�J�����X$bQ�Ë� p(^qpn����� pB�w<�GwH�`�������@���P�B�Hǀ���s��H�;�v�`�nr���@�ZE&���n���H/�d�DB���Q��FC���z�����NC�	x���*�����!Peôo�}?&E�}��+Q�����SR�_��O�I;\���+\9�@�{�_���O��~C�;��E��Q���?�vNY6�l4�_�_Hs�b�S���'�H�+$���!�C����OR�&���d��}T��� �%L8��U4����OD1��_��m�������[?�J@k��֕��;u\�y�ia_��K�Ibi!fK��'�F�e��% 86$��OG���O}�ĐNGU�D2�vZ2Bs���[�:�B�>sam��sd�:Q�)YZ3ݚ�K6\3��۽����^��������}�!!;-uG���21	k���%��!ykN���v]yw�q�m/�w�r����u�{Xk2jK�\�3�X$�a%,,�?9a|�:���!y�
�$aN^���K����2?4R��]¾�R���  p�HZH�wY_r	�o@)^E�Ջ��ж��(���g����[;�uq0���b��[����G�`��ֺ5��O-3�����
m�VF��~"S���F��.��7���J#�JE����]Ё�d|�
W�W����;�s���
�B�j���Gj�m$�Ǵ��T'�ߓ��W���t`��o1{X��v2`�{"r�u��	B�u� GG�X{��Q��p5���h�IlGW���,0�]0�|g�?�?���Q�u9�L�y�	9W������y
�cǆ;��\������/��B�[�}�"O��	���t+y�&0#�xA Z�BQ��b�f�t�Y;�-�(ݏ�\�캻]�����;Cvr��D��t7\�P���1Vx�@�;�;�r[�������f��+�+±x�^���D�^Vh�U�+�Nn������	!K8��У"QѼ	
�� ��A���Y]^¡
��V�`o��ן$~H�_��+�~���zv�;��E� �#���;�|9evS&xn�ƂiBu��t,�A�7r�X*4&��~*@c�}��A��*�;�|L\w�"HN&K�E�م��R;;r�Ec7p�6;�t
vo�)�H���xdi� jЃe ��Q��?m���)6h��H�2˲}+������w;�(6���0��t��X�'����PV�^�1Ƨ�H�q�V^�f*-Ѹ�Y~�����dP��!�M�����A�T�f��R{�~�B��6�{!�;�s]�t���� Gh_(4-�?))]!x,�e�=?�`4ь-�ۍ�YTul���cu+�S!�)P�� �Ⱒy��(�h���x-�	X��x�p�W�5���s)F�	�a��p!(v�Gqy|�B�t��Bo���sp���t���;X^�;kCFTq+?QsN�n�A!�9U�����$�����A�Rަr��=�s~z���;�sv @Q^V���Q%C@�+����	+��q�q�o4�1���&�s)Ehr�Ką4��+��7�����AЈ�R���k�]x#�:0, �VB
P#��A?�u��;W|�v��7
�+Ȉ�G#�����`se���S�,E�wU��
@o[�u���uB�#��FM;����b+'+sT��[138e@�<^�.ͷFC�cC+u)ւ���d�HPs4��$Ķ<r�^(W���~�D���+�T��4t# �y��t4�PQ;�6�6�c���|�Z�jT794j�ۻ#�RH�<�l�4�_�cu>W+I����T�P9^b�Yz˃PY�
���.�Ы�z��R_��L^]��``�Wm ĳ���t�|,�jq������W��d�8 ?k�;u��� <�������4A�&#��N��؏͍���y��y��uy��z�[��_�l��d?Gb�1���d��l�k�/4r��d'j=X��f�G�m�J��!�[H�E7 �3���x/�FW�[S0]�AD�����#�" @!�����"𐉻�X9}���
{ȶ�x����T��%� v�H?}���D��s�4H< sET��.|
���@  7�q`{��;߅�T?ؠR$>HL�Buދ���ۥد���}��9	t${�f5ajW��6WW��uc����$t2�m[,K@>x�5�����2:@G��e�B-�}6|��³���w��3۲}�t�Vd�
4w�gt���e۬�&�(h�U�����qa���qg��7xG�V�J[-/8^��I���-�^�+��I��8@w�.��@Q3t�I32$A&���c�	��'Q�I���x{��8u'A|YZT�w9Z7 w��,WV��z���(פ��q��9Y��(�v�D��P��Y��#p])������8��B��Er�3v�Q��ޡzB��B;u��.^�^}%���},~�Ŷ�w,�
�{ �X#��nԻ�e������*��DJG��e
pK���]	X��v+�	 	��$((t���jbB�^��Ę��*\�<�"W�P���舖Q�X�y���X4�=:��FT�m&�8��i�J,4��SeW`7�B	���'�	j��2��Xj����=j����P����An
e�쒁W����ۏW$���"Eů�
�Q[�&"H�;i���%*�����֊�����_"��� ���E���m�D^��jn�J�t��[E���1���`C��N>t^����*�FtT�߾��Lu7�c�E�~6u,����4�#=�EakDB�����E'"��",htl6w���JA��;��"����MM��}�-j�Ԍz	�D@�E�u�㼱�h�c�@E��]h�}㍇<SL<C��6��;���.��w�3��I�n@�t(j�V�c�{�u;D�#o���B_�~��9�tP��	�������o�^
�[(�cd�� k�jg~8��Zlip�WF������l�m�p�md^���- ~���f���4����w�u��
=�@[Q+�}�mp��W��2�D���TD]�	<E�]�~�t��!��S���Z�!S	wٷW�F]�8D0uf��d(\"��)� �Fm|�*��eѲX�Ez8A��}v�e6,v��4�F&+���c!�c��f�m����J�.��2on��f۟M	'̀&�8R� ���Ǣ>�BNHJy�2�(�[�Q�ǃV �õ�j�~���t�Ƕ�6p[�Jbת���'HHAV���-4�9�%�5��|?+1�ó��)�p�݊ea�G��?^c��$�NǍxt�1"ҷ1v�uC-���v5�09E�����xt/X^Q��t*�x�._	�ݍ�o^^��.M�9��5j0[�l�^�ux���ե������� P���6*�{�]u	�]����G*�_�Uˊ<]t_G<o��51=��6G:[�R��s�f��:"!x�
2P��F���[��/��I������BNu�2��\�jA��Ћ��D4*�@>�M�u�!�����]��GcB�Bf��%�^t_�5ؿ�Sd�]�Ӈ�����ݾL�3˅�t`΃	l�uR�IA���l�`�0�AN�x�MY���L6Wr�*�+��Ԩ��.����i��x������Mj�9��<�[�(��-V��r���h���R�f� ��K�YN�u7�PRM�o h�\n���s��=uO�!�t�h����@�%�T�X2T��Y�-��S~�e@��MS�]2���u�8}SOF[Dk8v
2���7�|_�%7�v�U�����HI1��U���� �(�R~ܛ�� V䄇���Q��3 ��t?pt:�v����
l���?�<����8M. lt7S58�%�ٍ|�݈�(��h��ߋF��x��� �� Yu)]E�U�.�"Up�,c{��a�8���h��8�@��B�p�6��F+|��,�
U��V���'�$>�P�v��}�ub)U[[��T��nq�[��hV�	l�5[0����,�a'%�;h��Q��b8Տj���+h!uV~�� �%�VZ{��(�p���/���89�Jx���	�
�
�Rٍ�C@��(J�A�ǋp§�QW��"���� �<�S� ��qF^���[�v�@��vf(�Ƿ�ޕN�轗F�F/}�V5�$�f��"�mt�w��u����Uo��Yf�'Wtg���>+��H9XI���U
��^C��6�,���?��G�MJ��Y�k�@[��tP��%Zh�@ �R?��f:�I��M��j�h�_Ux+�9�_�N ��jsEo����©�A1H��L �� /Ѡ��ۉ(��G�;N�P�m��\� (5*b�B�	7p[9[�+"� |�Z����3��U�	�uU��`D���T���\�*���3��P�����Zn�#q�Y��J�'���;�-� t�pt�b�~��B�'[F����JM��	!�a��u��m�_F�y�'|�����A���B&3 �?k����:��D@K7R�mmI�h��R]l����	:;찠 1>A���4:4��3í�����!e� �&�6	� ��g09%�-��~��\� F5�RO��W�g�8�>`�O�eƖ�XB�2U�&g���6C�rp7Zl���\P��0u�U܈�
�au�Т�$!@�h3T-D���n��u	�t������t���N*��n	U8x�	@@���F��Q��HT���}�� ��0�nO��=R.���x���x�4�ґ[�~P���~��B5��ƀ'�]�ZvlG_��	t�^Q�y��;�"T:�E� G�+�� 
���]Z���+�����`��h�0�u���v�t�LP���V��g�T�Ϫ�59U�~��D������~$X@
��P,°�*t.��Y�
i�=*X�����X�������W���� �G���PQ����W.���Y����?-����GlX�b-�4i������(��F@*Q���&�2/�� ���P�'�<+�����ɈG�6�mgtq8�����.�&$� =�k� ����\���75�0Q3��
��G!%a)�:	L5�RP�� ��Z�rA�c���O�wq;
h�|t:n�ct�A=t!@d)�������%���� �{�l��3�t�.v�|�s����\T��d�U��{@l�a,�	9�E=>AH9E&V�����+St�S���	"f/�����K����WV ���m��L���0Z'�6��9}X]̼���R��V+���o" Gڷ�90՝ht�$@ ��ؾ0� �^*a����&�����S�-���
+�	t��(
 ��2�_4�Zж�����jV���H��\���6hn��),Ő�10�:A);\\D�x���.�CG�PC$��~2�=;�m1,Y8O���X� ����w�t�P�P���6 (eYY���ISM����j�!���M�u͌��LU���.�!�4�(��I9�EK�h�&U�Q���SH�m������ V��j虼~!��=�> �:�! ���H�K81&<u��<2WFP];�6?'6⫀����F�`�B�c�F�G�A5�`$�wf�S��\��9��v�J�(���3��*��3V��;�r��t�9tt�0B�.8NUr���S�B�Cj@	Z><$mO6�z�� 9u�󫪉]��|�@���`��.D~���UPA��mq	;�%�j�#	y�T>�VO�4R�jU������A�t,`	J�i%WP�#��;�w����n�� ��G@*�A8���A��th�r���L����P��A�����;��j��}��Y�ѥ�R@y�SG��g'}�@=���YlS8+�5S��b�ǺI� k�j렪\�.ņ��l��6�,s��po@-\�2�p�C�\���C�L����uv��زI-�
lK����-0�ޠA� �,�{�Wq�"3����Q�_�F��"��V
":T|�
���菌��MHU-���r�D�� �� ��Wk�[*�T�S;�w��*�C�A�  ����ɪBB�B�A�5<D�_[��5-��zP�V��m��Y�#�Vl	�V)#���d�h QKo�.\�rMg�j@�[�d�k�sV�h9 �޻[�〠TD[� ��I�r?�Ѣ��KȀ� ���؈�IarzwNvöm��LJ�^n��X�j���XI���̟Z NQpsKVU�ES=�'UOr9U��r`	�G����
u�O����Z�Z���#U'u	�N��
@�F�3B�G���#�0�t ��s"%Iu�[^�:UKe�� 9��u0S��*�C,���f
3��W��0{(W5��o��9>t& �'�ft�~�MP[�o�uij#(}�t{��տl7s�VP�8csm�j �Kuxv'GI��?� f����u8�d>2���l�$W�J��]��M.4w@rP[Є�|��f�|�@��ci���Kg~G��%�b9~u]
�A p����xl�&�g�rpl@ps'�T� ��G�n�<��faQ� lA.��	�l�	!ȱ.z��4�W�v5Pv�oP;��0;|Q@�;{w� �P�	C|K~d�h@U��;X(��t5vX�3X27Ŝ�jڐ��p!��7��@� �Xg؈�5�`zS4x0��Vø1p-�� pl����&��(�U`�<�,�{��I��^XF����?��"��@�Vk0l�!6@VP�OK�8 غ�s�x�� %��sO;>|C;�`C܉>GN�pDu��H��5���۪���j{Pnl�b+�����ɻ)�Y�LG�J�xD�A,t�P�A����z�	�Q]�;��"��g�v�$�D$�� �m7	�	&��������"h��4*s�	}�
x+9tU/~;wۧ`کGn����h�o�����j�ڢYk&��lHT���Q64�Z릉sbR[	ɿ���!D�7ax��S@Bt<w���8W>�xb, ��vuW����aBCs6n��X�>�F�u(@���s(���H�3�H��W�N� !8!.��x�B[���Â��P_ؼ1�-����i��}
}Yx�At�p��"�{S޷�ó�NS�E� �@Ϩ-� "5��		l�N�@+U�/U�ҁJ(��-zŒK����`x���?�Z*(�J#��U�^��0!Hc5O�PW��n�	u7x7�xp*�
2"�EXDs P�AN�0Q+x��	��]�kA`�����|��D�j�x���Y&0<�Ί����8F�[���Q��I�Ri�\�C?+R�]K�H�s� Ң�">�v&����.]�T"�a�K|���� UwH�u:W�t~S��=3~�P_�f���9tV5H��+�Q�;�Ng.`KDjPF"H��1NF�?�Ҥ d<M��W/@N҃�� QQ���g��Q� ���ptha"٭�8%��f(�A����������|.2P�߶��[^��SQ����{U�mU���Q�VӋK����].�����M�!]YxX�{�zM�����Qu��[}:jt�Z��c��`�Yt)�u�'�U�\�7���cU���0���*�X�OR_�/�M~P$��A�#*R;}@�@� ��Q�0Oc�d��xk?%Grd�&��-���):+�Y|���h'j`�e�aPntl-$�� q�Y(2!08@�2!DP=�X��{ ʐ�2� g��>3��u�9p`	 ;`���9�@2َ�ξ`8U=�6)�:�>����UV� Uķ�g��T�;��%�����.J�����'G8�t�,A<ɀ� �A��.��8w��H��*���x�NH�c�����> ��g' U�ؾ8�t�ؖ���ؔ�.��@�
�Ü>�*������6l��;q"���-KF�wXr��r�$wj1����Ҷ[��1�^9�vl��_r�w,�-�*�8�`�X�).���Pu��;�}bo���!C�魪
��j!S� �>Y
3ɸ�������� �x�y�|�Z����h����!�ɏs4��n�S���
���	 A��,�|ѹ����AE�f��i��j�r_���w+V�����|xLbND�>�}����"Q{,�)h{,h�fy/��s8��T��i�D���WѶ�n#�H��7F� �	Ԭ2U
J��]��9t�t<b�>,�jjK8��Y;�+Y�*��i�0
~K�$3����B�]��P����[d�����qWt����e����k�����uf�f��-���"��*�.����F��#b��7��Y^<�
���ѩ�S����m̀��7f��1�f�fݐ~&WP;}�V����[Ո�$������f ���0Q�_
G�B�[�ꨯ��h�+c���1.�Y9�~t����"
�]�_�@�2��ɜY��H�h����u�x����C�_��
q!tu�m��A�4�O�JF;s��|�� 9�H��Y�t��Y=�>�mש�#����H����7#�OhW�p�}�,�{�c��A_$�~N��.$�&|YU��6Y3t��?$����B��V��}B�� W���#�T	8Q�#���n�1(���$������@z8N�'�����0
,���	C���:O[h�gj���bF ��'o�j�V�[�V�Ny>6P�����<���3�ɍ<�I;�[c�h�O_��^0��y�Ў�;�~�u?��p/T����HjjD����$�B�	������W
�bS!fҋ��*'��ڡ����k9G�h� ��}��P��.�r`������W�ɉ]�|m��rif�G�BP�
+�+j�Bk�&i�3��U-\$�WYȳp뻅�O�t,��{��4ZA��y��@-�+����X9
u"A��0�ҁ��u��?�Y ���}]����������.U�FS!sr5�+��n�b�H�GW��40�V��|b@P�{�u��6
����^U �dŎ��u���8�@��oG �73�9Z��>9#�`b�� Gν�G�pD�mlkT)0� ����o0!��\���o���`�at%��<r�G]EuA@����Fo����	~��%Z���G�:���DU;�o7\D�k�T>`��m���+tEt6�HLU�D�9]��v�����hoȎ��@u}	,�Z�Yus����6 o��V�m�7!�uYȆ�e.`{�b�Ht.��^h�u@�2��@%jR�c�u.�̢�����-�.�:t��	�mA�h�����3������}�C[Ta�i���0p�XۊX�D؈`XYo��	~z Y��0 ׂ@��7�y����}��	PJ���E�p�<��Dj8i�����2�V:Y�$�H���l<'�8O�m[Qy_�_��� ��Y�A,�oTߎ�~B�<�:�.1P���&:a%
��[��:A��P`�P�����u��ƐM�����C�z�n��BD�A0���֨3[��[���Ɋ�]sm�r |=����YYG݅`�ץ��XX����<0�n��Mm]���}`�����v�9b<�ń"E=ghS-	�'�P��@��ξh�K|�v�e,0����@1PQj�`�uԊ}0Z���P�����FbǨ���6�Aѻ(:�t�A�n@�M*�,0�e��Ek��H40t�8u݊�m�@��
�M� ��h��r@�����t�h瓭��kYnD dH���������d(�*�PO9 vo�aLe�$)ޖx�5NP
>"�-���[D������Q�~��'$�Vf�h�Xz�0�4�FS8]���.�$��}�_��P>Oǘ��YY�E��-����%~�P�F"8a�h��o��MϘ:��M�{Lh��m.��D��o��AE�^Ky��	j��-��d|tGmk|�^��h	��RBF�

��0�"]"E���w!>� !�oS�d,V;���B���n���3�C#�}���� 0���.��{0U/"��6.0YG��F�H~D��/Y�TvG��[}+d��]��9�|��-��j0���a1n�SV�W{�$;v�p��Qx���)SK �4q�.;���:n}��|&�}"�
�!l�u� G�#]S#�1k��QSR��5}u��021,fu]<��ɲ�]�`A��L��N��!��j(Q?��j��~�@qS���
f^_���P/�L� 6P�tGK ��oC���r-�����Q u�|��$�h��&��&�2�� /#J�N�8�mH[� D�h�k"pԡ�@𣢬ϭ�Ѝ�}T�)���o�����F
�'�� ���$���@�df0�<���Wq�*��
8��;+���W;�|�9=g}(�m���䓱͞!��ύ��Y�����`{�F�@o�$���+�Y�M|��B	�(5LM����8��2`$
.u��h�#x��*(�`	����wZFC;�|�?��3ۋ#ۃ<���[��uMp/u�	����X��H�����R�d2�eWpu,H̉>-`)CBTNػ���

��C���[/|��Dt��aX&��"�7Wx�!� �{�	��D �ǖĤ����$ r��C����%	�D��XV�}�W��d�hv/Q�An��<=t��h8�Y�O�G0����� �;�U�c;�1��(����A{A8t9U�����YE�?I"U4b�Z5�.W��h\�Y�7�]��E��͢���~���T��`��[��0��� �d�VA<�Epd����5H�h�tUS[���@��؈�݀a�/;���=l/�E$�PV5nk"�HZ����EĬ�<��DT�t!�Ф�S+	V-�h�
$7���;��8"uD|@��"t�1�)��]K��%��;2��F@�����o�$�F@F>C��m�@D���s׊�)e t	�F�[		u̓H�1V�Jf��e ��9"0�gD+@�D�'�R����wq;������"\u_���P�,�UjRҋ}�jߢmxx"��*��Ft��8�������Kh�:��C��\F�E�V�뀧�
h?��#��.(&X����@���0����'a ������I%�hS 4U
rw�x��n��u�g�t1���{!�(X�����$ f1����G�w��Cu�l?�yf9�ۭj�@@�m���+������@�F�]�I�K%n5;�2���j�#UW*�k�%!x�T9�'\Tc]�V�\,�}m�SbL�u�%Z�
V`u��ߥ@
u��+�@jU�'�D�EU�4���\�W=`E����][8ŃLRk*!h�RŇ#��PlYZ�v�r���E��z�;�����AD�r��j��#�;�԰U�l���u�p�ޑ���u�+�\�]Ve���xPmYs��$^��Ye<v)κ�'����j����At<�;Tq��XUv���W\T�٬{��Ӕh  �~�',߱�_�&�G�-=�0�6*f���j�WX<�^ɟШC4WN��6UI��+;�v	��@"��#.!Q|�@r)���	^`�ǺC����]���f������Ww]�<��� #ю��FQ��DƖ��VY��\��ZQIO+I��c%�������#F!G�?4M�u���|t7�L�ldݎ�i�K����욦i�������n�i��ˍ  w[g�	��d�i����f{]����T�3,1�0
+31�A8@B$�������w�����e��K���bON+�H!,��ۇ�Xx�m:���oNXOV���]B����؀�}�)�'$�@\�������EZ�[�M��p�]��4$7g4M�4�4M�t�GB��@�PX\KB�h|����F�#r-!�ٷ �"�+��0�����W8"f}��w�R՗	B�qP�KU�Ѐ�-5m�.IM�+T�p�
X׉����Āѕ��$�f�Y࣠�(.0
� �q2Axj<Xݖ>c��9u m�(uu���J@ck�\)��`�(i��@]u~�JQ����,�ֶ0p�W*D��vf�+#��V�g��:37�F�Uu6q�1�ouu�ru������*�VA���D�<P�N��0�}	�Fo����t���!��K��u����u3�L��&L�w	�ݚ�9Kv'��E�s�Bg"m:p���W$�T\�(%Z�ܦC�*G�+25c������83�`Q�g/��*?�&��a�,� W�?�]��X��,�/�:G�w�II��v�	,�O�z�@��`�XM�}��ܐp��-�\�m %�M�u�??,$]�4����k	��`$�4l��;�����Đ��!@�m8����@-��)�.6
y��[��i�������#@�i�ן�������� ���V� �E �-K:B�.U��$���ʐA���F�s�`Z-�!?�JO�:
��;Њ@�!`8R�w�l�j]��R��^�7����DV�^e� -���E�j�	�������M
4QPF(f@������kSE
#��"H	DpD�(�˪��ƈ��T��� VEm�]�!��t&��U��3�(��^��6�
)��� 蠧�(��cQ�N�.����~*9e|/
VA�St���Vj	ǂP��H�,u���w)r8�u�P4��*�3�>��lS�j?�����ɤj
q�~ŶA��$BG�.�UL���u	w[��#�� S�����Q	��[�/�P��@sD�m� s����cУ����SԠ������V(��h�+2�u.�~ �?���;F�	)�>�@��mA���6v����H�F��U} ��/�SȀ7@%_�&"� d��?���u)�-�G��?�4�8����0)��(G���n�`�:��ƨdE�ˀd������Ɇ*�1���D��N��*�����% ���E �0�P�Tlm�L-h	���ň`����o.��Rd�����C�=�Q��#)[���
��
t	��^*�� @��ȍ�)+���pg�̋�;j ��"�۳WPq�40�)b�cC�y���x�| dV@,���ً)�T�?�tbj^+��#���0������<��WQNdZ��.k��)�,�0�,G ���̒�8�-VWn�h�8��+�8V�"^���5*:h����Q�IX7h��_sA"ڡsf	�Fjߌ�;(GG�r���dg�26�3����@ROp�Ru����Y2%NA���U�Vh��-�K�fȯw9K�X�FD�X`Z��Qq� � ѢӋ�����mJb!��V��pj=�fD> ��zD>�������P>F�.�(>��YΈ�;ݶ�h�+DT5�0|Ë�� L1���%8h����[�UP>�^V�FxA�u �h����@��S�� ؓ_�#�X�C�g��#�INW�4��P3��� ���p�+ f۰j�S,pYj��fD��c�n��<���HH��u�W�C(�D����w6�gKU)�V_jUv]R�PV�����e�wg@#��8�<�PhxE�+BΦ�_��	AS^��D����k�=>/��F�V��x�)`V�9��YJ���S�j
�j�h��uY"�S�Az�5��n#]V"��k59EÀ�/�[�7�A	C0|)1�8�]D��S�$ ��G;[|�^`��-*�UV�;�	���)�	����� 1`TS��t17(tGuD�#r����K���x=X��^M��7L8thaE �RR�*/��+j$Y�y�|o��h�m)�� ������C�����^�N�>��0�V�>�	�7V��g_��z�s ��������W�W�h	UĬ���VV;SWs��߆���<���&���<t6ZhU����~�_"9ItISj��6�������6H��0�[�m_D
��^{Xj�X�CY7�2vNZ+�~Pv{��PP��3�~'�As(U,VO��b�	 0F�@�:����4�<�����)0I#�A l8L�h�re��$h |{�[;�~sr��BD'2�|9�blHSY)EiF�<�u��G�U�`��k�6LY���dB�aT[�Ja��+/S� � ��;��'��[�1�L�_7��0Dk�#C-FPYd�F��4�����Nlw0��u��;�7u��?������$���!*JE����TL����Z�;�J_^�!ʞ�%W � �����U}��s���*�q�h�*��F@�bC2�7���Y] ��Ht�#v��w���S�6�V��<U�è��RQ< +�ф9$0�F�h/ѡY�m�$���hu0���JA��U��:6z�~��F]ի�u;�Q|��t$��TA-��E��=�[0��/� <o�v8�<u�C�E���Z�I�s!@��.�-�^�CD)jn��s�+a�^��
�:*A�v���- ���F�C�D18l�/�u�}�c
���KWj��t.��{w����n�j\t��@�U����+F�]Y��ep�E�� �VF3A����&�VAa�:Ƌ�
���ye�8��CV|U�no���T9Ĭ�J��M��g;^#�pۢhm�H盧vHP���f(��@�"�9���&�0t
[-�Pt� IOT/R����]��(v�}s#�;�s�[�1t*&;�t�3h� �tM�+IE0�RCL�uݥ��u�>&/�BP� #�8cI8K9��Ru;�7�; ��9�t˩x��(S��#M~�7<�
fŁ+��b۷������ƶ%M�h϶A�Z|�%��X) v���QC�?���1+V�8��PguW��k1�2��,���u�t��V�դ
	�(կ>�yh]��Y9�*QT�	C�Qd���n��H�=ux�ts�F ~tmj��� �u�ү�6A��K����v���XB � b��y������G��4Y��j j Zt��9E`sx�Z�����-���HV�� W��A�aiٚ���ֵq�#	�V�+K7;Y�m��A�B�DР��P���~��YY�%UA�[6W�jX9Z�<�H�,Ͳ ���A�I�������D������#ֽ�B�R��9*p�ҍ;���Zũ�ou�#�^�;͸ �]EqL�b�r]��[Y;�V�d��z���j&�n89���[Ì��˺�ʾ*}�/t;��% ��|P����,�s�V	.t&'4�K�_�O"t���$0��F7ʙYMjaM�:*�XY+��������҅�uF1}�u�����
VP�|�^_ޡf* J�N�a�L��Y)LnQ���7�p�Q�7Nx�Wj���҉ U,��[����Y�\���͍_f�^��Ȣ
����w\ڝM_�+�Z�λ� ��]!CS]�3b�`?LW|8�Y7"�.���+�!��AO@/|t+Am��y��8)�'���[[.^0�4J�ъf~�Ç^�o3�%f��A�溋��,��}[	ThO��̮E�����]}�-�+����Ew��#���EF�E���jT��M�-5h�ިv�[��Lo5jY�|�������5���gOK���B�y�.�PPÌ�t�Me�/Hڦ��H�
IOu*���> �����B&~�u&�E� �ۅ^���� �y�>T���[�E�u�[��w!��G�P��G��Ri+OJ�>�<;�6!`�?����Xw^V	�JB�Mv@#�[ђ�/[ܒ;|(R0��d/aM��&�wr7���W�+���!� ��#b�T�5���w0������ZP��u�
�T�q�nT\� u@1k܂�}�)� x�-���E3R$J?�%������un �a���$�AC��,�m� X��J�`�/S~��ۡ~�(�7B�70#���AO��-�0Z���u�6���'|�95|��b9� ���>��B�1�BwWvV1��Wt{cf�X~�ް�6����:P��?u���f��˨hP�Q}����Kډ���]�ފE�P`{L#~
у%d�.qO���ǆ�nF%����@���Bup��X�پ���ƽ�F��#J=o��S��MӼ�=D1f2�K&[�fʁV����� 6LP�9�D��[mT�1j�q�U�.�����?���m�_����
�LH"1w����wuB�Ht����Ggj��P� t���g�ۉ�)tP����W�m��T���{t��#��S���`M:���H��H2az�R ���0a���z��@�� = ��r_�j\m( 2Y rB�/k���-j��B.0�
&0�B�4�� �5.	QÚ�
�
�HW��((Q�!��ۊh �<	*�R��H�wÃB��E���B�g 	��qz(�� 6.vmFQ�����\�{���)����A2 $P�
����Ä�_�����<{]f3öQp9�t�F�l���J���m�]�+VT��NTuI*��X�FX��~�f�u7;}�����%
�ǥ�I��&���d�=��� �!/"A�q�K�|⇉jаH���T[4�Xi��=���Y�k��� �%����h-��'�^A���@�F�9r�¨����<I��;�s9pA��H�ط�;�tB��^x�a�3jV�[8ˢ>��[��D�8�"F����>Uu�k��WFS��,�=��4�^F����7��}_�f�PpɆJ�R K���?8��\,18kɾ�P)H}Fj �EZ0�~��� �����N;�uT���J|��&6E�w� la��a���X�@2ja
x�Qrs�AL�;=^�
�4�^p� 7�6�Q����5b�.��0�`�����X�c�^�E��x���j���4?�h�
(l�]׉˒�%����_`-�Զ��H.g��+t+�@���[+���*�lD�N�3;6mAVGN@����~�S�SvQWp�(���}�S��SĚ}� 	zU����� �#�yM���nc_9U"�A+"c�C�B����J�Pk��\���|�s~�Ӿ�s��Z�e��!�@�ã:�mf?
�\ˆLm���	�C���Z��\��C��e��hQ3ڏ ڧ���.�Z��2�	_�ZU�1��u�*�G��j^i	�wo�	�Ꟁ�1|9���Z2L�:$?T<[ʾ+t�EԷP:`�-<Z����@�X���]��N7|Q~�J��1���qt1�t,����RC��E~c{c�Fe.rj}O���j�	S%��@t���WYO�:�!͵��)�Q*��}9�,~z�V7� tK��( w����մsP����>�a�*)��)�r�؃
g��Z�^hP9��r6� '�p��2DsF�+"��-#EQ��� s��]u`Q�"��8�q�
O����DE�A�tdV@p���e䰄�����2��D\��k���K<{!i	XO	�/0D���*h���Yl�\ltHHZ��"R�X.�%N
O��
����b@��"zV��rG3��*�@����=���Ё�P<¾�Q��qI4p,7�O�>��m}�>8��X9���v�}�|�E����HsE�Q�����EjB {�t���}��@�h3��hPpM([U�Z#	���}�
�+E=�~0��A�U]5�Da�`�Gϸ���S��r�A^=����W}�(W��K�=|u��uղlH��ʕ�#���8{�`x
7l�	H�T���_�t��Zq�Y����A��.����h��4ͥi8��
@�V4M�i�ؤ̀|uE�]Ⱦ�7����f��W�"�i�m������t�����XP�M����?�p�E1ЋC-��·%�K�u����[�uf�# 0.�l�Uf�z�If�����D;�u?���VC:@�4!�F.�ܖt�A@^	.H���2#�C]�_#gc�~��h- ��w��jἓ�!�i�����M���et_z�Nf��k�M�v�����)��	����9P'�D���}��?rc�a; �F���V���mG3tf}5~��#���}]�� �_�u������Z|��h;�E��Yu�n��g��~w��`�N?O�
n� C8~PQ(q+��u`I�+R$�ONVb��:�Ed*j�6dӂ�T0E�7s�-ъNQ�	���A�aG�5�T|0�=���7�H���@f�� *�,�Q�(S�djn�/M%�f۠,��� ��c9�H,�bWI���A��T��L�/`�+X9*!��� �V.��ƻ��Ou�ɀ&�
f% P���%���$��A���K
]W���O���FR��
3�#�#ʁ�ժ���f=�GF�.TG���#�
������
�z�?p�:fĻ�*ZD���V
�[��ƍg9���3�
�����S9�� hܿ9�M�6�X|$����� ����Z�z�I�KG �	h� �F�,O�>�����Q�P��1����%r)�m���u[�VmM���X�����M~%!����ΎE�9�c�}1�En����=�,�:��@�K�V�C@XUA�R�/ހw�-P[h8B=F5v�� ނ� 	[����	�\}�v[h�0f�(��L_l���ޖ����sf���nm�.�N�NhTo�����m��Й�l��1 /TR�~kb7^S�p��`�m �c}�� 0�k! ��*�!AZq��T�t)b�a'�R%���<(I��ƃ����c��nCu��3�>ED�u�в�_�?�(� ���*`G�ø�`N��K���%E���2+�����  dTU�C	�!��n7������t.�.zDAL�:P�@0�P��$O��4] ��5M?Wk��i?w�ُ`H�P�麳 TsRW@g�op06X�m�P
`�`� � `�2�@�?�6�X���uS;x8�u�`�Qh6�`(�6� ��H��`�TU�`�u�+t`�64?��6�d$�l��_�D��`��\l��0��S|l<؟��l,��l�Ll��Rl����#rl��2?����b"��l�Bl��Zl����Czl��:����j*�`�F��J�6��V��`t���3l�v6?��l��f&�6�a߆F�`��	^`�6��c�6�~>��6�`n.�6�`��N6$��Q`C� ���qؐ4�1?��a`�6!�$6ȁA��I�6Y���`�y9��6�`Ci)�l�C_�IlH��U`�Ƿ�uؐ4�5?��e0�6%�_��E����]��i���}=��l�!m-��`���Mᰐ4��g����s3a���?���6�#�_�!i��C��Hl�[���l{;����k+��vX��K�H�I�W�4�`Cw7?�6ؐ�g'�l�Â߇GlH��_���?������ol�/�����`�O��P2���%C����P2���%C�PɩP2��%C�ٹ�2��ť%C�P�P2�յC�P��ͭ2�%�%C�Pݽ����C�P2��2�%ӳ�P�P�˔%C��C�P2�ۻ�%���P2���%C��P�P2��%C�ϯ��P2����%C��;�㝟W�O[M���Y(U���]@?�X���}��\ ���Y�Zcd����`?�CN6ؐ�a?'��10��!� /�,���o��ٳ_�
 � g �C��S�>c���� inflate 1.3 Copyright����995-8 Mark Adler Gl��_w{
s�x�[�� �8=&�#g����'�/��>��o� �� 2dS` C2$Cl�/$ pC��+s�	O�! �A ���1� ��+L�������x�;@������!�#���0�������#�k����0w,a�Q	��m��jp5�c飕d�2���������y�����җ+L�	�|�~-����d������ �jHq���A��}�����mQ���ǅӃV�����l��kdz�b���e�O\�lcc=���� ����n;^iL�A`�rqg���<G�K���k�
��������5l��B�ɻ�@����l�2u\�E���Y=ѫ�0�����&s�Q�Q��aп���!#ĳV�������������(�_���$���|o/LhX�a�����=-f��A�vq�� Ҙ*��q���������3Ը��x4���	���j���-=m�ld�\c��Qkkbal�0e������b��l{����W���ٰeP�긾�|��������bI-��|ӌeL��Xa�M�,:���R����0��A��Jו�a�Ѥ��������j�iC��n4F�g�и`�s-D�3_L
��|�����<qP�A'�� �%�hW��o 	�f�������a���^���)"�а����=�Y��.;\���l�����Q������ұt9G������wҝ&���sc�;d�>jm�Zjz������	�'�V��}D��ң�h������i]Wb��ge�q6l�knv������}Zz��J�go߹��ﾎC��Վ�����`���~�ѡ���8R��O�g��gW����?K6�����H�+�L
��J6`zA��`�U�g��n1y�i����F��a��f���o%6�hR�w�G��"/&����U�;��(���Z�+j�\����1�е���,������[��d�&�c윣ju
�m�	�?6�gr���Kt�J��z��+�{8���Ғ����������|!����ӆB������hn�����[&�������w�owG��Z}pj��;f\��e�[E�i�b�ka�����lx�
����T�N³9a&g��`�MGiI�w����n>JjѮ�Z��f�@�;�7S���Ş��ϲG�������0򽽊º�0��S���$6к���)W�T�g�����#.zf��Ja�h]�+o*7������Z��a���- unzip 05���`�ls VoW���an�����G�H"J\2 /�[3�y�K^b8c�dK����__GLOBAL_HEAP_SELECTE��MSVCRT��#��}PT{��~2_X��~E 50g>�� (8PXF700W  ہ��`h` (px �`�!s�5���n�l�-<�)�(nu>)����oy  O����Y7��'�487�^X����7���7؀u�ZF��YJ}��~P��/���)GAIsProc�sorFew���uree� KER�NP32Oe+E�7�0r3timJ���(>+`T{�7��SS�ING�� DOMoNR6028�n�habf t�mh[�iVJizh�o��p7'7no�u�oܚou� spa� f{l���-ci8�n7�<�\6stdVx�35p vir!��3�c# cl(�6�}'4__*ex\)�k�/at��_\�[ZX�ueX1l��desc+8n�MF$�ed��W#7mm�5r�th�a!cl�0�k/4[knd�a.�!r�ڷ�m p@gram Jm)�P6/09O9�fhA*mS.w��+8}gu(�W�s_`+f��ض�nng�ot:B+�-&dM-`9`���fVis�C++ R��1��Lib�rk
s�l{E!P�: �ڰ[.  <��%,{H�kl�;J��GetLa>Av.6؎�u4GeW�/���d2�RaBoxRKKKjuOrE.U��^� H:ml d �}]�,n  y 3n	Z�/d/ A&���D�ember�NovO
���ho
SeptAH[���f�J��n]8ܞeA�il#ch�B��u{��6n
g_WS����KGC7yC?�_�;3#'daCێ�F^	ThsW���|Tu	MׂkSuC;�{�7/'#�n��S#QNAN -F Ds��FS#*18s��?FMϢ��9眰�������s��� !M9!���|�p'�%D'0�k��PU =Yw(��F��t��h���T���^�]�e�����Fz%�nH�cM����A2>}+p�� RSA1��]GT���	��e��L�7Ҥw�L����A����-����m����"�����0e�B<�<��L����"��#�r���oj�����7�B�m��ase CryptXph P��zvidcv1.L%l�d۷ds= []�������L����������ؚ�������������sh�`j-a�P��<{��� ���5����YG��R�0]dy8u,Q@�Dx�6b +[�������}�'W��N���_�#f7+�7i�������竇Nd���@���}�� ��H���۳�w�xͺ]����qtG��	�.�����@��`=���������������}��2���� H���P[��������� E!5����A OR4�����������u�D���ˏW�"vZ�>W�{}�������Æ����PW�k^����a�38�����i����>t���O`��Ԟ�_�ךm���3�����#2(j�y�e��n�. ��6�����//e�&s�ۨ�������f������3uě����E���}���AM�߾���pm�#g�	w�O�����*s�O�ff<�~����*m�����v�a���X�1G���3�W��?1�̇tOt�9X�oHTT�F[m#Re'[PZs�ă-On��?�N���:�տ���&ni���5��m�|�l8�ce��T�d��Mb��њG�2_��E�7 ��N��3���g�]?	WN����.�A%"ˋAs�Oרy�p��`�����7oC.���&�_{�[+ى�Dv�w�������6ͿGIΘ�}�B+�u�����������T|�KƤh�g�N:�W�����i��Ū?�S:��{�7!і�����A\Ӟ�r�E(�s�0�ҡH��>��-!���,��S,�C®��/-��e�ѪH��>�-����!:.%�:uڵ��a�F����H9.�]%�Hr�Ea3���V�i��Ȳ����z�k�$�&-ȗ/�G!����{��Em�;܄� �e��誰O����y�}����t�s��e�m�
I���T�k�7
\k�V,��Ww�����,�%ِ��~�+�`�7�)�y��������Xɀ���I^;@��R�)�M�����~���+:�L<Ϋ�JCj�PĘ�=���Vgiă2�Y��'��yI0�S�}���y"d!}LX�i13�� ��;�88��M�n�rI�������<�IW��������igp�_V�r�����h$n���W�rW�����+�q�hR��Zs��i/o��K�o���s5�Z��jU��&x8H����t���a�Y��]�3I@�W�Z�)o�������fT�9������Ɏ?"�c�V���.T� G�����{n����_��f.62?R���1G�P2�x����o����S�,0��Y/P��3�	�W  23��w:15:31007;�g�����������r�����~?�&�����r�oQ�*hw�b[�귝j/p���^��3p��������`b>����Qesٓ>��2[VG����|_�J�5B��Q�������?q����Q�� ��.) �m7(2^����Ƚj����G(TN&׶7��&��?�/��/=��������U���h������v����s���+C��7�NǇm"W7�V,ٙ�Sz;j�(�����R8�t���
�/�ؿ�ي����:����_Sd��?&p^�<�}���_D\��l��h�[���~\y�P�������uG�N�h-jk-es�}�'����k�rbt\ht�����\dr�rs\etc����b٣��n��w������*.���2.�@7ݰ���fq#���Yp����r� �d�b��5�������
��-�u�n��D&+ѽu�N4��_���i���S		y�y����I֊Ј�����h�E;S^Cb��s�8:ڒ:�X&�S���?A(�O߱�J,�Lȿ��׍Ҹ���_���4$"�q���9�Ex�UU���31�2�b��Z��t]�=u�;�x�����9꣜�K8$o�9]����+	���o�ۥ�.��m�#�g)��������P�\�8h㪕y�}�����3�%.Zn~����o ŏ�����Z�|$�R[[^�7]��������G��#�;�n��zi����I��K_K�0D� �xyE�o��o�t�\��b0�j�������\�� g0!H��k�j,�\���]7���C9J�`㿺T+W������r��bMT��{��\'���w6����Yj�k�D�*]��N�N��{������҃�^�����˳����H=�,8@��\�,��Q�)nwʟ�Q���)�2n>s��5�j.~����w��������H�_��f6�S��ї_�>N(5a��?�Xz23�]Cj�0Y����u����Q����g�!I���1-�K��J�C�X��tET��3b�jR}t�)u*�+U�lD"�Je���;p�@�����Y��@�~��~<��G�dyai=s/��خ%�/%��؂����l�������	����P��A9?R�v(tH��n��=���Ɠ����������%�}'��F�/"�n�B�c��I�^�Z�v{���K�B�P�����cx4#C���/���)z+k+>��r�����NM�찕,.�_��L�G��qI<��q���4y+wha� u�bS����F��Ȃx<$\�y����?V�/���X�|[6�b�]����'�H��qx�����������I�y@���.�K�N�%���q��A A���$�A�]�?i���|�;k[�Z����n[�g����
�da/J�?���1=Q��D�[�/0��ҧi55zOd�������(�H�+�T�T��0��S��/}�/*+�b��vǆ������?�s޷}�����R�c��C�~�_��ꍳ��v��b/7Z`��D�������K����u��ˆ�����6@e���mᡤ��M�h�ؿ����5�
C���C����c�ջM:�r��ι�k��?��V?āf�3�>�� +&}4>�_��:N��6��F�+T^�����h%>;^��p��j|�������Ƃ��������Q꼹�����=���_�:#{�~��B�mƎ��F�R��������ah4�c������]����ƍ�N�c'[������E4��)#F��(0@����*"�K��������#y).\B97R��z��i��	SGl�%���b8���q��V�x�����7����������(���[����!u0	#�[����Z���ٺ�����t
y��ع�D8X��]�wKDQ��������浀�h=Y��_��K/S�x�#�Q�.#7p��O�^1d�˃I
������"����JR�3W��Ξ�'��B?��.�PQc���������7��"�x�R�����
t�~룭n������槭��s�Xý�[9�U�����n�Ap;7~"Wj|�V!̴��s�L4𿏜����D88���������]��o0���,��-)-?͐�����#�y��� s�~�?���w�R0�j�5Џ3��q�l���4�Iu��h-�����Й�X����z�������w�P���d ��_�1H�[�6��V�� b6".F����0Z��{��fK�T��V�n{�~鼺u;M�o�*���I�- c�,��?F�Q����W��7�J�O�I���f�_���]�s�{�e1����~�\�!��L�_i.���o�0�Ž襼����tne��K�S��a��*+gz.3��m[2FN	����V�d2IG��� �E*d%v/L]�:CDױ_�m�RCS�����������7�H�I�l{�j�_���^�gO��k;#>��Bcv^��;�G�%A�:�Ð���Ė��`^����B�9LS����r5E����:�z�������$ѩF�p'Hn���5g����;A�J�@{����9���Y�T��)5< +;o6Z���E���)O�_ǈ��Ů��UWk�0��9���o�FeIHA���)7��zO"��b���l�_��	5!�1�#��41zbjM���G;x��;�c��0��y<��%�@�����/3�����c`�1����`7���Q�p�������s�t'Y�%��F�/��i;���C���~�z�S��<G�?�R�����S��rk|��F�6�S���0�C�~�ıIf�"��?�h�Z�K�Q�l���wpX�s��������e�%����c���+�qF��%��e��[�׋5]�<���v����_��8�Kj���K�?|�w��7C|�9ƚ���g	i��Q�dS����sf��.ih��PҦF)%��}*j�������_yТp��"p�M
F�Z^`���80"^��>ԓb,�\����c9[�ϻQl�~��2��� #��%#�\'��3�/_���n�X:�K�����Ę�5ݺHUdm.8������U����@�.gQ�v�����k*n��-�䦃�:|D��3[������C��\	Check W��?Xj�0[����b�Ȯ��»��J�F���z�Ï�� �SK7N��>�����f
K_����3«.��q'�B��~�[��V��':g+��"�9���ߠ�/ � ���V�Owx��P�{}�<�ώx����!:ԃ-�	{�!J�%�ڳR������|ꈷ�H�.cQ�^7�	�/�	t�i��#g��� J�������r����F�+vK�Φ���$9������R4�,����,'�U�Y��_��{tprefix: �~/$��&\���K�2�Y�I������(#x�w\������g�F�)~云g�n�������[��c�3��'A������(�*��l&CO�B��T9���#�C]U��3KF(`<"h�ۆ�V�/Z�T��4����[�a#îQAP�u�� ��p.h���K�ߞ�	\Q���`���#'_������������g�|ኄ�C�pS�;��x��b'����o{��7��?�[E�T��2��k'�X�����[z�[���]��KD$4X�ht9:/�/L!7'��N���?[�(C������-�a�DK���t7.��"�-2g��y��	������)�y�7���"=|�l��Y��%��{ m�t���M��2N��/���	����^!���N6B�����D��~�?����c��*8P�����Uo��3/�j,�Yv�$�����Zg�R��#�Æ��o���
��;�jC�7|�귷W|�_|��-З'��Д����o��1(s�e(x#QXM�B��@���K���hK�\@m�`0d4�6�g,B��f�>"F�0K����|����[q��톑�w,��wy�u�BN�aM���t�`4E�z����j��[c��TG�-n()kQmx_���^���#�>���-s�G�GN���{t^�r�إ��#��������%�к�9�~���c"��/��;���7��R#h�A�6+�^���;��w�m�%�,y�mɏ5����l8���5XS��K���|��e��@�h@y�h1���/_47�rY�d]����N<#L�<B���I��b�������ؐ��1��k������V���@JW�����0w�*7���c*Q�U��o���rs��O�GK����}���K����f�~i7��~writE�d'��o����GG�~�����o%:ʉ�c��S����B��b&�D�������gP��\��R�Z�"y�����sg��Z���/�H�-rJWIN  ���"Jef  �7�Q�� ��҆P��2��b5�
^��q�z�@���Х��e3[�2J���+nB�{��
�nk�;�J D����TotalI64uMB, F�e"�{� Qu$M���7DiskSE����xASMemory(Avail�/�m�[/PR)=luKB mﶰ/`Usn%m[�P�� TmP�_�?�No Syst��b��Ze�,h*��Xtus Uh%�� J5�]�%�Off�AC P؅
�er-�P,r��TMSSDFXS{��R	PSE36PATMCA�GPGE
  ��/TRGFp���l�ICGEAM��؛noD VMg�
MW�p���� f�����CMOV(PXCHG8R RDTS��~l3DEw ex2ns�����s'MMXB�w!CLFLUSHc.X�E2��/F8���PU�P�%�5��GHZ/�#RO�B��[COR OBILE;!��*�hs@;��typnppl��6J%uc l	��Zru�fn�EtygAut٫x��ncAMD �Nus�sK� ofF�.C�}5�ou,T()X���LakuageC��f�9��sW;mp�C��mNk3�g���n2X#OS 5�?�-��v.|(Bu'�m�d�)'� (�&�}Ya�kM�J����W� XP+VMS�v�� *i�Ma� kU���l��Q�
�>bifyyCtx��V�E&s�րB6E�>~��98� ���98��R2  z�6�CCERV��o!�T�LANM����WI�d�T�8�ƿ+YSTEM\CFr����.�ol�\
\,߰�Op�rT 4.0 jm!`}E-
	@?�E��F�d�n�A�a�po�Z�n2Ce4/���rdAo�Xb gK9�d�c�fn$l��al8H�eW�ml�k�a̘{��+HSVi'�`�03��pCZ(3+/+ ��2K ���@��4���7c�!r��ڗbuff���͙��Di�pJ� d#!�l�4�8m/*6���lew7e�F*�2�aCW0������Rk� �   *5��yMǻ4l�	t OP�`w�te��oo�HyorZ-�F�syG}s?�9�F�/8��s {�u�jC'$�dy�
�7ic~L�$v���o?ubsiMed'e�=�y��-� with�o����(g	;>!��B6?#�� �KƯ�a �cf �V�J��;we3��bI ���� �r@Bj��e���<../\  rA�K��ꘘG�t���NEL_�s 5����9�� ����� *UUU���0JF�=����+�{���ز`G��C  ب� uG�� 	-]]����"�
�S�g�`�y�!/����}7����@~��/��ڣ 9;�o��@�����/A�/Ϣc���� ��[_~�	Q�췻�^�__�j�2/�������1~9�;V��7����> ��4ݗX�4M	 �2	
�6;W�`[ �2!5A�6�CP3RS6�`W_Y{l���mpl�r�/���d�����G���)���l���o����A����zFg�+� � ր�n�,�� �=A߬�+;Z�t��x����e����0Nm3M��:�w��Ӛ�{4��'/Ml���3 
�y���	�
'O�<�\,y����|�G�<D�xy����y�z�����n4G{*  �L2e( �L$H �d�.,!��X?���; J$�   `��/�� ����$F ��@d@��U@d��"F�xϞ�����5�@�A����{����~p �i��������i�����ȼ��i�������i��i����|�i��xtpld�k�XPH/@8�i��0$a�t_���� (۝�~����p� /��p�(� ,PST�[��PD?�wJVP���6 �@ �I��e�@��C��P�$�����]о���4����������N@��p+��ŝi@�]�%������O�@q�וC�)��@���D�����@�<����զ��Ix<@o�����G���A��kU'9������p�|B�ݎ�����~�QC��v���)/��&D(�����������D������Jz��Ee�Ǒ��������Feu��uv�HMXB䧓9;5���SM��]=�]����;���Z�]�� �T��7a���Z��%]���g��������'���]݀nLɛ� �R`�w���}���� �?q=
ףp�?Zd;�����O��n��?��,e�X���?�#�GG�ŧ�?����@��il��7��?3=�Bz�Ք���?����a�w����̫�?/L[�Mľ����?��S;uD����?�g������9E��ϔ?$#�⼺;1a�z?aUY�~�S|�_?������/�����D#?��9�'��*?}�������d|F��U>c{�#Tw����=��:zc%C1��<!������8�G�� ��;܈X��ㆦ;ƄEB��u7�����.:3q�#�2�I�Z9����Wڥ����2�h������R�DY�,%I�-64OS��k%�Y����}���������ZW�<�P�"NKeb�����}-ޟ��K@��ݦ�
 ���*�JFUQU%�ɨ���dTUUU2���*JFU���!�9�ح;�@�� Qm�$S�aVAO � 푽�o�t sI+h+�Pq���u�{� /c%l_s]��r5ge!��h3sP*b��1�HMA�6�V~Y� ��R+t1� ��y+P|�	�\7C�n�1l(�޶PW ���+Sf���Uau��sP�d�+���s�&��?Q�DCH��{7�t�nt��uCsG�mG��5t]adUUd�e� ݀M(ۇ�v�W� ���, �# �6,�f �3l��2,[؁/f2,%ٰ�82/쒓,  �k  `c�e2&�2�%K/3�h�l`��f�2,F��6�_��2{���,�/��32�le!,/`�e��,�f/l`/[2��,/�0a�e ��%�/� ��k�/2���f�/�f�L2��3fa+��x�,��� �QU%Uɨ���dTUU2���*�p��[E�losP�"[D7E�et�AddFD1�ϴ"�uleHdA��/U�lushFiB��?#De��X�S
	tt�nۀzuslsc�� �at	�(QkA4C1���AExit �� ��"2k�IsBadRePI��kgDyD'��A �E���Š(X(E1(�okc��InfoL�:t�aoe�mpihV��"DFb�Ȗ�"E�p�,X���T�NAosDimK��moL	KF �6�)ia�6af�d��.[de��}}ng�W�gI
�E1s،�f'9�lR T���0v�zUnhyd ΰ�SD�ter�����A��mf�w�ck���@��uplate�m�0k(WaF^�7�GS�Obj��0�9,�a�',�Id�?Wri�a�Bl� dOf���czzlF� 0��v#	�Ŋ1in�G0ʘKwR��Cns�Cw�"r;�lS�y6(��e){SE\�³�Id"pý`2���f,He�`4wp�Lkv�Ƒ1cުG�A�-
��l;�T�4EuX���yAll�K6V�	S-7OEMCPOj��	A�LC�%��Map�W�He)��YYIU脽ۆ�D�re����
5�Rtla%��wind"ԬK�/�omm�ne%) %4�/X�X'Zo��-���_able��lC��oyq�fI`�+F�`V�ai�� C�r�{�`M�By>
0�%���� 
��PzDIz�tKey��pk��as�1x#��ڛgOp� �#����c���Q��\,Ұ'a�a��M?�Zm�eP�/e�
��,�Depr3�(z�
�
��^°rg�ureg`�`7g �a�Acquir�A{�#2H+���EQ�s�sDD�8��us��T1lo/흌�%DC!I��ct�
@�6B_^{v�>BltQBkO f`W��DJog��"�,;Kil� 1�rtdam�E.>h�g��+lgI�%d;i�
�B��f��Zw7�sA���!ag�ν`F�!t���"�Aei��!�cp���ʰtfPx0����eD��2�;#6BA(��pF�Ɩ"�@Fؑt2O0�l�A�E�YCa;{,�.ck�)@�	�n������[�M�o����nsTeU�X)�S�����^�!�]8��`o�est/��g?�ch�t@��r��o�/�	�%		
K���w���Ŗ+�f d&[/��a�o�+&>&8;	;�����++<
�j=4�}  -# 9����'	d�k%*l3.�n��i
	4**M������

2�		�o��	v� ]/.0�,����AkN]@#���u%E����3flf[C%dc��v #)&n��,,�!&�l{	7$�'��vs�%"#�){%km[n ��/ke	�$1)+���m�
�)
���	��,{�ff�	o��4U;��^p�f
��a	NM��{W	|<G۶�7B�d����o89H )- ���pN!�&!�ε�mTb��o�sDC4�#u�0���
"���}�#x5c&R��ڶls��F��ֶ�&&pB�xa�
l[׹��ڠ	 �[�L.��$�.Xs1]#!3�(D�/A'��w�c:+4&Nٰ�?"(7+�Fی��Mj��5Z�c�$vJ��u0#�1"	B���ݵ*3�%�Z'(m���Z=�4o���K�_�rضn5Lqar$I��mn@*E��ߑ
 v@	(CC{3%�a�&F��.|(T+�HE�:�ǔ�0����h7H38Q6�)�Enk$�!�
oeu�w�$B1:B�_����>/1,.@�d05t��I3�^�ݻkC�u_�	x��%�(���O�����c[�?�
.��m�>�-03xi��b��{v/8�!z
w��q��a)�o?9n���]}�k�uO��w��K��Q�X��Z��F��o�َ�1�ﻏT&-�6-w+S�5��A��>�
< I�{��GE�XzY�������n�3
�	lz�/��RlA��-=+	-�dC������]^,=/*�[o��G<�7�����#$����~ck7�V*.�����W�),��ooS	)�&'@���-8�;,/^N1#��j!Z�P���[�&:)���3A+rA�^=���>+9�0/ A�&� #5*v�ƒ@
6�'!�7Z��bT(.��	�D`~.��K�=#0�/699+����)9J9*9K89 )9(���9GX:+I+76&%0�����%"�*8�������"���m�� �gK=��B�����	`��N��� �mz6�|�Bc	s��C"�W�,{��g A��H��{B��w�=F���	tl7#s�v^��p־@����h�)��
�X&t�.W� \1�F���!)�5�/��YB���c[���N^SB�ZW�:l>&�	�`K&��)	������p/�vAi�1iH=\���+��^�(�� 7'N,�Y����-/�MCI^hV��7q���

sC\�B�38�	o7h��R8�8n