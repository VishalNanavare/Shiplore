<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* database/routines/row.twig */
class __TwigTemplate_8d3bb696a0db2f3004e118578284cea1 extends Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        yield "<tr";
        if ( !Twig\Extension\CoreExtension::testEmpty(($context["row_class"] ?? null))) {
            yield " class=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["row_class"] ?? null), "html", null, true);
            yield "\"";
        }
        yield " data-filter-row=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::upper($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "name", [], "any", false, false, false, 1)), "html", null, true);
        yield "\">
  <td>
    <input type=\"checkbox\" class=\"checkall\" name=\"item_name[]\" value=\"";
        // line 3
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "name", [], "any", false, false, false, 3), "html", null, true);
        yield "\">
  </td>
  <td>
    <span class=\"drop_sql hide\">";
        // line 6
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["sql_drop"] ?? null), "html", null, true);
        yield "</span>
    <strong>";
        // line 7
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "name", [], "any", false, false, false, 7), "html", null, true);
        yield "</strong>
  </td>
  <td>";
        // line 9
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "type", [], "any", false, false, false, 9), "html", null, true);
        yield "</td>
  <td dir=\"ltr\">";
        // line 10
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "returns", [], "any", false, false, false, 10), "html", null, true);
        yield "</td>
  <td>
    ";
        // line 12
        if (($context["has_edit_privilege"] ?? null)) {
            // line 13
            yield "      <a class=\"ajax edit_anchor\" href=\"";
            yield PhpMyAdmin\Url::getFromRoute("/database/routines", ["db" =>             // line 14
($context["db"] ?? null), "table" =>             // line 15
($context["table"] ?? null), "edit_item" => true, "item_name" => CoreExtension::getAttribute($this->env, $this->source,             // line 17
($context["routine"] ?? null), "name", [], "any", false, false, false, 17), "item_type" => CoreExtension::getAttribute($this->env, $this->source,             // line 18
($context["routine"] ?? null), "type", [], "any", false, false, false, 18)]);
            // line 19
            yield "\">
        ";
            // line 20
            yield PhpMyAdmin\Html\Generator::getIcon("b_edit", _gettext("Edit"));
            yield "
      </a>
    ";
        } else {
            // line 23
            yield "      ";
            yield PhpMyAdmin\Html\Generator::getIcon("bd_edit", _gettext("Edit"));
            yield "
    ";
        }
        // line 25
        yield "  </td>
  <td>
    ";
        // line 27
        if ((($context["has_execute_privilege"] ?? null) &&  !Twig\Extension\CoreExtension::testEmpty(($context["execute_action"] ?? null)))) {
            // line 28
            yield "      ";
            if ((($context["execute_action"] ?? null) == "execute_routine")) {
                // line 29
                yield "        <a class=\"ajax exec_anchor\" href=\"";
                yield PhpMyAdmin\Url::getFromRoute("/database/routines", ["db" => ($context["db"] ?? null), "table" => ($context["table"] ?? null)]);
                yield "\" data-post=\"";
                yield PhpMyAdmin\Url::getCommon(["execute_routine" => true, "item_name" => CoreExtension::getAttribute($this->env, $this->source,                 // line 31
($context["routine"] ?? null), "name", [], "any", false, false, false, 31), "item_type" => CoreExtension::getAttribute($this->env, $this->source,                 // line 32
($context["routine"] ?? null), "type", [], "any", false, false, false, 32)], "");
                // line 33
                yield "\">
          ";
                // line 34
                yield PhpMyAdmin\Html\Generator::getIcon("b_nextpage", _gettext("Execute"));
                yield "
        </a>
      ";
            } else {
                // line 37
                yield "        <a class=\"ajax exec_anchor\" href=\"";
                yield PhpMyAdmin\Url::getFromRoute("/database/routines", ["db" =>                 // line 38
($context["db"] ?? null), "table" =>                 // line 39
($context["table"] ?? null), "execute_dialog" => true, "item_name" => CoreExtension::getAttribute($this->env, $this->source,                 // line 41
($context["routine"] ?? null), "name", [], "any", false, false, false, 41), "item_type" => CoreExtension::getAttribute($this->env, $this->source,                 // line 42
($context["routine"] ?? null), "type", [], "any", false, false, false, 42)]);
                // line 43
                yield "\">
          ";
                // line 44
                yield PhpMyAdmin\Html\Generator::getIcon("b_nextpage", _gettext("Execute"));
                yield "
        </a>
      ";
            }
            // line 47
            yield "    ";
        } else {
            // line 48
            yield "      ";
            yield PhpMyAdmin\Html\Generator::getIcon("bd_nextpage", _gettext("Execute"));
            yield "
    ";
        }
        // line 50
        yield "  </td>
  <td>
    ";
        // line 52
        if (($context["has_export_privilege"] ?? null)) {
            // line 53
            yield "      <a class=\"ajax export_anchor\" href=\"";
            yield PhpMyAdmin\Url::getFromRoute("/database/routines", ["db" =>             // line 54
($context["db"] ?? null), "table" =>             // line 55
($context["table"] ?? null), "export_item" => true, "item_name" => CoreExtension::getAttribute($this->env, $this->source,             // line 57
($context["routine"] ?? null), "name", [], "any", false, false, false, 57), "item_type" => CoreExtension::getAttribute($this->env, $this->source,             // line 58
($context["routine"] ?? null), "type", [], "any", false, false, false, 58)]);
            // line 59
            yield "\">
        ";
            // line 60
            yield PhpMyAdmin\Html\Generator::getIcon("b_export", _gettext("Export"));
            yield "
      </a>
    ";
        } else {
            // line 63
            yield "      ";
            yield PhpMyAdmin\Html\Generator::getIcon("bd_export", _gettext("Export"));
            yield "
    ";
        }
        // line 65
        yield "  </td>
  <td>
    ";
        // line 67
        yield PhpMyAdmin\Html\Generator::linkOrButton(PhpMyAdmin\Url::getFromRoute("/sql"), ["db" =>         // line 70
($context["db"] ?? null), "table" =>         // line 71
($context["table"] ?? null), "sql_query" =>         // line 72
($context["sql_drop"] ?? null), "goto" => PhpMyAdmin\Url::getFromRoute("/database/routines", ["db" =>         // line 73
($context["db"] ?? null)])], PhpMyAdmin\Html\Generator::getIcon("b_drop", _gettext("Drop")), ["class" => "ajax drop_anchor"]);
        // line 77
        yield "
  </td>
</tr>
";
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "database/routines/row.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable()
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo()
    {
        return array (  186 => 77,  184 => 73,  183 => 72,  182 => 71,  181 => 70,  180 => 67,  176 => 65,  170 => 63,  164 => 60,  161 => 59,  159 => 58,  158 => 57,  157 => 55,  156 => 54,  154 => 53,  152 => 52,  148 => 50,  142 => 48,  139 => 47,  133 => 44,  130 => 43,  128 => 42,  127 => 41,  126 => 39,  125 => 38,  123 => 37,  117 => 34,  114 => 33,  112 => 32,  111 => 31,  107 => 29,  104 => 28,  102 => 27,  98 => 25,  92 => 23,  86 => 20,  83 => 19,  81 => 18,  80 => 17,  79 => 15,  78 => 14,  76 => 13,  74 => 12,  69 => 10,  65 => 9,  60 => 7,  56 => 6,  50 => 3,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "database/routines/row.twig", "/home/rootshiplore/public_html/pma/templates/database/routines/row.twig");
    }
}
